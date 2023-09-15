FROM bitnami/magento:2

RUN apt update
RUN apt -y install unzip
RUN apt -y install nano

RUN echo 'Mutex posixsem' >> /opt/bitnami/apache/conf/httpd.conf
