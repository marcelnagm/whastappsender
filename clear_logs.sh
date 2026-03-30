 sudo truncate -s 0 ./storage/logs/*.log
php  artisan config:clear
php  artisan cache:clear
