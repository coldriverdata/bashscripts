#!/bin/bash
mysql -uadmin -p`cat /etc/psa/.psa.shadow`
