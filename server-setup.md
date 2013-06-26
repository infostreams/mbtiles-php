

Setup for mbtiles-php on AWS EC2 instance

Requirements 

* Setup AWS Ubuntu LTS  12.04
	* 	Helpful Link:
	* http://docs.aws.amazon.com/AWSEC2/latest/UserGuide/putty.html

* Update (sudo aptitude update) 
* Reboot (sudo reboot)

* Add Apache HTTP Server (sudo aptitude install apache2 libapache2-mod-php5)
* Add Apache Module mod_rewrite (sudo a2enmod rewrite)
* Add sqlite (sudo apt-get install php5-sqlite)
* Add php5-gd (sudo apt-get install php5-gd)
* Add git (sudo apt-get install git)

* Edit cd /etc/apache2/sites-available$ vi default
*		Change AllowOverride to All in /var/www directory
*	 	For more help visit http://httpd.apache.org/docs/2.0/howto/htaccess.html

* Restart (sudo service apache2 restart)
* Test if php is is working:
*		cd /var/www
*		type (vi test.php)
*		hit ‘i’ and type ( <?php      phpinfo();         ?>)
*		press escape
*		enter :wq!
*		Go to yourserver.com/test.php

* Add tileserver.php, .htaccess and [filename].mbtiles into a directory in /var/www
*		If you are using git use (sudo git clone (your repository)
		
* Restart (sudo service apache2 restart)






