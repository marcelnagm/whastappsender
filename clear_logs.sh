 sudo truncate -s 0 ./storage/logs/*.log
 sudo truncate -s 0 ./storage/logs/services/*.log
php  artisan config:clear
php  artisan cache:clear
