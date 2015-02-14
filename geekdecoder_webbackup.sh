#!/bin/sh
####################################
#
# Backup a vhosts httpdocs directory
#
####################################

# What to backup. 
backup_files="/var/www/html/geekdecoder.com/"

# Where to backup to.
dest="/root/backup/geekdecoder"

# Create archive filename.
#day=$(date +%A)
date=`date +"%Y%m%d"`
hostname=geekdecoder.com
archive_file="$hostname-$date.tgz"

# Print start status message.
echo "Backing up $backup_files to $dest/$archive_file"
date
echo

# Backup the files using tar.
tar czf $dest/$archive_file $backup_files

# Print end status message.
echo
echo "Backup finished"
date

# Long listing of files in $dest to check file sizes.
ls -lh $dest
