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
location=/root/backup/geekdecoder/admin_geek01-`date +%Y%m%d_%H%M%S`.sql

mysqldump  -u root -p admin_geek01 > $location

gzip $location
