FROM php:8.3-apache

# ตั้งค่า Timezone เป็น Asia/Bangkok และเปิดใช้งาน Apache mod_rewrite / headers / alias
RUN ln -snf /usr/share/zoneinfo/Asia/Bangkok /etc/localtime && echo "Asia/Bangkok" > /etc/timezone \
    && echo "date.timezone = Asia/Bangkok" > /usr/local/etc/php/conf.d/timezone.ini \
    && a2enmod rewrite headers alias

# ตั้งค่า Apache ให้รองรับ .htaccess และสามารถเข้าถึงได้ทั้งผ่าน / และ /erp/ (ตาม Reverse Proxy ของเซิร์ฟเวอร์)
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && echo "Alias /erp /var/www/html" > /etc/apache2/conf-enabled/erp-alias.conf

WORKDIR /var/www/html

# คัดลอกไฟล์ระบบเข้าสู่ Container
COPY index.html api.php auth.php ./
COPY data/ ./data/

# กำหนดสิทธิ์ให้ Apache (www-data) และ Rootless User อ่านและเขียนไฟล์ในโฟลเดอร์ data/ ได้
RUN chown -R www-data:www-data /var/www/html/data \
    && chmod -R 777 /var/www/html/data

EXPOSE 80
