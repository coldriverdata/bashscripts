#!/bin/bash
# Synchronize Files From Remote foodcoup.com to Local foodcoup.com
START=$(date +%s)
rsync -avz foodcoup@198.50.162.33:/home/foodcoup/public_html/ /var/www/vhosts/foodcoup.com/httpdocs/
echo "total time: $(( ($FINISH-$START) / 60 )) minutes, $(( ($FINISH-$START) % 60 )) seconds"
