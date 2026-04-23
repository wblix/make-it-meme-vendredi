<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use React\EventLoop\Loop;

class Chat implements MessageComponentInterface {

    protected $clients;
    protected $userList  = [];
    protected $rooms     = [];
    protected $gameState = [];

    // Connexions en attente dans le lobby (pas encore dans une room)
    protected $lobbyClients = [];

    private $memeImages = [
        'https://i.imgflip.com/30b1gx.jpg',
        'https://i.imgflip.com/1bij.jpg',
        'https://i.imgflip.com/26am.jpg',
        'https://i.imgflip.com/9ehk.jpg'
    ];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "WebSocket server started on port 8080\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->inLobby = true;
        $this->lobbyClients[$conn->resourceId] = $conn;
        echo "New connection ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {

        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        switch ($data['type']) {

            // =============================
            // GET LOBBY (liste des rooms)
            // =============================
            case 'get_lobby':
                $username = trim($data['username'] ?? 'Guest'.$from->resourceId);
                $this->userList[$from->resourceId] = $username;
                $this->sendLobby($from);
            break;

            // =============================
            // CREATE ROOM
            // =============================
            case 'create_room':
                $roomName = trim($data['roomName'] ?? '');
                if ($roomName === '') return;

                if (isset($this->rooms[$roomName])) {
                    $from->send(json_encode([
                        'type'    => 'error',
                        'message' => "La room \"$roomName\" existe déjà."
                    ]));
                    return;
                }

                $this->joinRoom($from, $roomName);
                $this->broadcastLobbyUpdate();
            break;

            // =============================
            // JOIN ROOM
            // =============================
            case 'join_room':
                $roomName = trim($data['roomName'] ?? '');
                if ($roomName === '' || !isset($this->rooms[$roomName])) return;

                $this->joinRoom($from, $roomName);
                $this->broadcastLobbyUpdate();
            break;

            // =============================
            // CHAT MESSAGE
            // =============================
            case 'message':
                if (!isset($from->room)) return;
                $room     = $from->room;
                $username = $this->userList[$from->resourceId] ?? "Unknown";

                $this->broadcastRoom($room, [
                    'type'    => 'message',
                    'from'    => $username,
                    'message' => htmlspecialchars($data['message'])
                ]);
            break;

            // =============================
            // CAPTION
            // =============================
            case 'caption':
                if (!isset($from->room)) return;
                $room = $from->room;

                if ($this->gameState[$room]['phase'] !== 'writing') return;

                $caption = trim($data['caption'] ?? '');
                if ($caption === '') return;

                $rid = $from->resourceId;
                $this->gameState[$room]['captions'][$rid] = $caption;
                $username = $this->userList[$rid];

                $this->broadcastRoom($room, [
                    'type'    => 'message',
                    'from'    => 'Serveur',
                    'message' => "$username a envoyé sa caption (" .
                        count($this->gameState[$room]['captions']) . "/" .
                        count($this->rooms[$room]) . ")"
                ]);

                if (count($this->gameState[$room]['captions']) >= count($this->rooms[$room])) {
                    $this->startVotingPhase($room);
                }
            break;

            // =============================
            // VOTE
            // =============================
            case 'vote':
                if (!isset($from->room)) return;
                $room = $from->room;

                if ($this->gameState[$room]['phase'] !== 'voting') return;

                $voter  = $from->resourceId;
                $target = $data['voteFor'] ?? null;

                if (!$target) return;
                if (!isset($this->gameState[$room]['captions'][$target])) return;
                if (isset($this->gameState[$room]['votes'][$voter])) return;

                $this->gameState[$room]['votes'][$voter] = $target;
                $username = $this->userList[$voter];

                $this->broadcastRoom($room, [
                    'type'    => 'message',
                    'from'    => 'Serveur',
                    'message' => "$username a voté (" .
                        count($this->gameState[$room]['votes']) . "/" .
                        count($this->rooms[$room]) . ")"
                ]);

                if (count($this->gameState[$room]['votes']) >= count($this->rooms[$room])) {
                    $this->finishVoting($room);
                }
            break;
        }
    }

    public function onClose(ConnectionInterface $conn) {

        $room = $conn->room ?? null;

        if ($room && isset($this->rooms[$room][$conn->resourceId])) {
            unset($this->rooms[$room][$conn->resourceId]);
            $this->updateRoomUsers($room);

            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
                unset($this->gameState[$room]);
            }

            $this->broadcastLobbyUpdate();
        }

        unset($this->lobbyClients[$conn->resourceId]);
        unset($this->userList[$conn->resourceId]);
        $this->clients->detach($conn);

        echo "Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // =============================
    // JOIN ROOM (helper)
    // =============================
    private function joinRoom(ConnectionInterface $conn, string $room) {

        unset($this->lobbyClients[$conn->resourceId]);
        $conn->inLobby = false;
        $conn->room    = $room;

        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }

        $this->rooms[$room][$conn->resourceId] = $conn;

        if (!isset($this->gameState[$room])) {
            $this->gameState[$room] = [
                'phase'      => 'waiting',
                'round'      => 0,
                'timerRound' => 0,
                'image'      => null,
                'captions'   => [],
                'votes'      => [],
                'scores'     => []
            ];
        }

        $conn->send(json_encode([
            'type' => 'room_joined',
            'room' => $room
        ]));

        $this->updateRoomUsers($room);

        if (count($this->rooms[$room]) >= 2 && $this->gameState[$room]['phase'] === 'waiting') {
            $this->startFirstRound($room);
        }
    }

    // =============================
    // SEND LOBBY to one client
    // =============================
    private function sendLobby(ConnectionInterface $conn) {

        $roomList = [];
        foreach ($this->rooms as $name => $clients) {
            $roomList[] = [
                'name'    => $name,
                'players' => count($clients),
                'phase'   => $this->gameState[$name]['phase'] ?? 'waiting'
            ];
        }

        $conn->send(json_encode([
            'type'  => 'lobby',
            'rooms' => $roomList
        ]));
    }

    // =============================
    // BROADCAST LOBBY UPDATE to all lobby clients
    // =============================
    private function broadcastLobbyUpdate() {

        $roomList = [];
        foreach ($this->rooms as $name => $clients) {
            $roomList[] = [
                'name'    => $name,
                'players' => count($clients),
                'phase'   => $this->gameState[$name]['phase'] ?? 'waiting'
            ];
        }

        $payload = json_encode([
            'type'  => 'lobby',
            'rooms' => $roomList
        ]);

        foreach ($this->lobbyClients as $client) {
            $client->send($payload);
        }
    }

    // =============================
    // USER LIST
    // =============================
    private function updateRoomUsers(string $room) {
        if (!isset($this->rooms[$room])) return;

        $users = [];
        foreach ($this->rooms[$room] as $id => $conn) {
            if (isset($this->userList[$id])) {
                $users[] = $this->userList[$id];
            }
        }

        $this->broadcastRoom($room, [
            'type'  => 'users',
            'users' => $users
        ]);
    }

    // =============================
    // BROADCAST to room
    // =============================
    private function broadcastRoom(string $room, array $data) {
        if (!isset($this->rooms[$room])) return;

        $json = json_encode($data);
        foreach ($this->rooms[$room] as $client) {
            $client->send($json);
        }
    }

    // =============================
    // START ROUND
    // =============================
    private function startFirstRound(string $room) {

        $this->gameState[$room]['phase']      = 'writing';
        $this->gameState[$room]['round']      = 1;
        $this->gameState[$room]['timerRound'] = 1;
        $this->gameState[$room]['image']      = $this->memeImages[array_rand($this->memeImages)];

        $this->broadcastRoom($room, [
            'type'  => 'game_update',
            'phase' => 'writing',
            'round' => 1,
            'image' => $this->gameState[$room]['image']
        ]);

        $this->broadcastLobbyUpdate();

        $timerRound = 1;
        Loop::addTimer(30, function() use ($room, $timerRound) {
            if (isset($this->gameState[$room])
                && $this->gameState[$room]['phase'] === 'writing'
                && $this->gameState[$room]['timerRound'] === $timerRound) {
                $this->startVotingPhase($room);
            }
        });
    }

    // =============================
    // VOTING PHASE
    // =============================
    private function startVotingPhase(string $room) {

        $this->gameState[$room]['phase'] = 'voting';

        $captions    = $this->gameState[$room]['captions'];
        $keys        = array_keys($captions);
        shuffle($keys);

        $shuffled    = [];
        $usernameMap = [];

        foreach ($keys as $k) {
            $shuffled[$k]    = $captions[$k];
            $usernameMap[$k] = $this->userList[$k] ?? 'Inconnu';
        }

        $this->broadcastRoom($room, [
            'type'        => 'game_update',
            'phase'       => 'voting',
            'round'       => $this->gameState[$room]['round'],
            'image'       => $this->gameState[$room]['image'],
            'captions'    => $shuffled,
            'usernameMap' => $usernameMap
        ]);

        $timerRound = $this->gameState[$room]['timerRound'];
        Loop::addTimer(20, function() use ($room, $timerRound) {
            if (isset($this->gameState[$room])
                && $this->gameState[$room]['phase'] === 'voting'
                && $this->gameState[$room]['timerRound'] === $timerRound) {
                $this->finishVoting($room);
            }
        });
    }

    // =============================
    // FINISH ROUND
    // =============================
    private function finishVoting(string $room) {

        if ($this->gameState[$room]['phase'] !== 'voting') return;

        $this->gameState[$room]['phase'] = 'result';

        $votes = [];
        foreach ($this->gameState[$room]['votes'] as $voter => $target) {
            $votes[$target] = ($votes[$target] ?? 0) + 1;
        }

        $winnerName    = "Personne";
        $winnerCaption = "Aucun vote";

        if (!empty($votes)) {
            arsort($votes);
            $winnerId      = array_key_first($votes);
            $winnerName    = $this->userList[$winnerId] ?? 'Inconnu';
            $winnerCaption = $this->gameState[$room]['captions'][$winnerId] ?? '';

            $this->gameState[$room]['scores'][$winnerId] =
                ($this->gameState[$room]['scores'][$winnerId] ?? 0) + 1;
        }

        $namedScores = [];
        foreach ($this->gameState[$room]['scores'] as $rid => $score) {
            $namedScores[$this->userList[$rid] ?? "Joueur $rid"] = $score;
        }

        $this->broadcastRoom($room, [
            'type'    => 'game_update',
            'phase'   => 'result',
            'winner'  => $winnerName,
            'caption' => $winnerCaption,
            'votes'   => $votes,
            'scores'  => $namedScores
        ]);

        $this->gameState[$room]['captions'] = [];
        $this->gameState[$room]['votes']    = [];

        Loop::addTimer(4, function() use ($room) {

            if (!isset($this->rooms[$room]) || count($this->rooms[$room]) < 2) {
                $this->gameState[$room]['phase'] = 'waiting';
                $this->broadcastLobbyUpdate();
                return;
            }

            $this->gameState[$room]['round']++;
            $this->gameState[$room]['timerRound'] = $this->gameState[$room]['round'];
            $this->gameState[$room]['phase']       = 'writing';
            $this->gameState[$room]['image']       = $this->memeImages[array_rand($this->memeImages)];

            $this->broadcastRoom($room, [
                'type'  => 'game_update',
                'phase' => 'writing',
                'round' => $this->gameState[$room]['round'],
                'image' => $this->gameState[$room]['image']
            ]);

            $timerRound = $this->gameState[$room]['timerRound'];
            Loop::addTimer(30, function() use ($room, $timerRound) {
                if (isset($this->gameState[$room])
                    && $this->gameState[$room]['phase'] === 'writing'
                    && $this->gameState[$room]['timerRound'] === $timerRound) {
                    $this->startVotingPhase($room);
                }
            });
        });
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat()
        )
    ),
    8080
);

$server->run();
