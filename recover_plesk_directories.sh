#!/bin/bash

echo_stderr() {
        echo -e "$1" >&2
}


usage() {
        echo_stderr "Usage:\n\t$0 DOMAIN.NAME" 
}

if [ -z "$1" ] ;then
        usage
        exit 1
else
echo "SELECT d.name, h.www_root, s.login FROM domains d, hosting h, sys_users s WHERE s.id=h.sys_user_id AND h.dom_id=d.id AND d.name='$1'" | mysql -Ns -uadmin -p`cat /etc/psa/.psa.shadow` -Dpsa | while read domain www user; do
{
cat <<-EOFedec8516cd5ed05ce745b2fb74620053
<HTTPD_VHOSTS_D>:$domain:root:root:0755:::0
<HTTPD_VHOSTS_D>/$domain:$www:$user:<PSASERV>:0750:::0
 :$www/picture_library:$user:psacln:0755:::0
 :$www/cgi-bin:$user:<PSASERV>:0750:::0
 :<HTTPD_VHOSTS_D>/$domain/error_docs:root:<PSASERV>:0755:::0
 :anon_ftp:$user:<PSASERV>:0750:::0
 :anon_ftp/pub:$user:<PSASERV>:0755:::0
 :anon_ftp/conf:root:<PSASERV>:0755:::0
 :anon_ftp/incoming:$user:<PSASERV>:0777:::0
 :conf:root:<PSASERV>:0750:::0
 :pd:root:<PSASERV>:0750:::0
 :web_users:root:<PSASERV>:0755:::0
 :subdomains:root:<PSASERV>:0755:::0
 :private:$user:root:0700:::0
 :statistics:$user:<PSASERV>:0550:::0
 :statistics/logs:$user:<PSASERV>:0550:::0
 :statistics/webstat:root:root:0755:::0
 :statistics/webstat-ssl:root:root:0755:::0
 :statistics/anon_ftpstat:root:root:0755:::0
 :statistics/ftpstat:root:root:0755:::0
$www:plesk-stat:root:root:0755:::0
$www:$www:$user:psacln:0755:0644:0:0
 :plesk-stat:root:root:0755:0644:1:0
 :test/fcgi:$user:psacln:0755:0755:0:0
<HTTPD_VHOSTS_D>/$domain:anon_ftp/pub:$user:psacln:0755:0644:0:0
 :statistics/webstat:root:<PSASERV>:0755:0644:0:0
 :statistics/webstat-ssl:root:<PSASERV>:0755:0644:0:0
 :statistics/ftpstat:root:<PSASERV>:0755:0644:0:0
 :statistics/anon_ftpstat:root:<PSASERV>:0755:0644:0:0
 :$www/cgi-bin:$user:psacln:0755:0755:0:0
 :<HTTPD_VHOSTS_D>/$domain/error_docs:$user:psacln:0755:0644:0:0
 :<HTTPD_VHOSTS_D>/$domain:::::1:1
EOFedec8516cd5ed05ce745b2fb74620053
} | /usr/local/psa/admin/sbin/dirmng -c -p
done
fi
