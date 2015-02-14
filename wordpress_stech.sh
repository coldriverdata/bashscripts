#!/bin/sh

#----------------------------------------------------
# a simple mysql database backup script.
# version 2, updated March 26, 2011.
# copyright 2014 roger pringle http://servertechnet.com
#----------------------------------------------------
# This work is licensed under a Creative Commons 
# Attribution-ShareAlike 3.0 Unported License;
# see http://creativecommons.org/licenses/by-sa/3.0/ 
# for more information.
#----------------------------------------------------
location=/root/backup/servertechnet/wordpress_stech-`date +%Y%m%d_%H%M%S`.sql

mysqldump  -u admin -p`cat /etc/psa/.psa.shadow` wordpress_stech > $location

gzip $location
