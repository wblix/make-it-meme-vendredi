FROM httpd:2.4-alpine

RUN sed -i \
      -e 's/#LoadModule proxy_module/LoadModule proxy_module/' \
      -e 's/#LoadModule proxy_http_module/LoadModule proxy_http_module/' \
      -e 's/#LoadModule proxy_wstunnel_module/LoadModule proxy_wstunnel_module/' \
      -e 's/#LoadModule rewrite_module/LoadModule rewrite_module/' \
      /usr/local/apache2/conf/httpd.conf

COPY docker/apache/vhost.conf /usr/local/apache2/conf/extra/vhost.conf
RUN echo "Include conf/extra/vhost.conf" >> /usr/local/apache2/conf/httpd.conf

COPY public/ /usr/local/apache2/htdocs/

EXPOSE 80
