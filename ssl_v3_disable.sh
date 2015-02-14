# -*- vim:syntax=sh
#!/bin/sh

die()
{
    echo "$*"
    exit 1
}

do_backup()
{
    local cfg="$1"
    local bkp_cfg="${cfg}_sslv3.bak"

    cp -f $cfg $bkp_cfg
}

get_os()
{
    if [ -e '/etc/debian_version' ]; then
        if [ -e '/etc/lsb-release' ]; then
            # Mostly ubuntu, but debian can have it
            . /etc/lsb-release
            os_name=$DISTRIB_ID
        else
            os_name='Debian'
        fi
        pkgtype="deb"
    elif [ -e '/etc/SuSE-release' ]; then
        os_name='SuSE'
        pkgtype="rpm"
    elif [ -e '/etc/redhat-release' ]; then
        os_name=`awk '{print $1}' /etc/redhat-release`
        pkgtype="rpm"
    else
        die "Unable to detect the operating system."
    fi

    [ -n "$os_name" ]    || die "Unable to detect the operating system."
}

fix_apache()
{
    echo "Fix SSL configuration for apache web server..."

    case $os_name in
	CentOS*|RedHat*|Cloud*)
		cfg="/etc/httpd/conf.d/ssl_disablev3.conf"
		service_cmd="service httpd restart"
	;;
	SuSE*)
		cfg="/etc/apache2/vhosts.d/ssl_disablev3.conf"
		service_cmd="service apache2 restart"
	;;
	Debian*|Ubuntu*)
		cfg="/etc/apache2/conf.d/ssl_disablev3.conf"
		service_cmd="/etc/init.d/apache2 restart"
	;;
	*)
		die "Unable to define apache SSL config file"
	;;
    esac

    echo "SSLProtocol all -SSLv2 -SSLv3" >> $cfg

    $service_cmd
}

fix_nginx()
{
    echo "Fix SSL configuration for nginx web server..."

    ssl_cfg_list="
	/etc/nginx/plesk.conf.d/webmail.conf
	/etc/nginx/plesk.conf.d/server.conf
	/usr/local/psa/admin/conf/templates/default/nginxWebmailPartial.php
	/usr/local/psa/admin/conf/templates/default/nginxDomainVirtualHost.php
	/usr/local/psa/admin/conf/templates/default/nginxVhosts.php
	/usr/local/psa/admin/conf/templates/default/server/nginxVhosts.php
	/usr/local/psa/admin/conf/templates/default/domain/nginxDomainVirtualHost.php
    "

    case $os_name in
	Debian*|Ubuntu*)
		service_cmd="/etc/init.d/nginx restart"
	;;
	*)
		service_cmd="service nginx restart"
	;;
    esac

    flag=0
    for cfg in $ssl_cfg_list; do
	[ -f "$cfg" ] || continue
	do_backup $cfg
	sed -i -e "s|^\([[:space:]]*ssl_protocols\).*|\1		TLSv1 TLSv1.1 TLSv1.2;|" $cfg
	flag=1
    done

    [ $flag -eq 0 ] || /usr/local/psa/admin/bin/httpdmng --reconfigure-all
    $service_cmd
}

fix_postfix()
{
    echo "Fix SSL configuration for postfix mail server..."

    protocols="`postconf smtpd_tls_mandatory_protocols | awk -F '=' '{print $2}'`"

    [ -f "/etc/postfix/main.cf" ] || return 0
    do_backup /etc/postfix/main.cf

    echo $protocols | grep -q '!SSLv3'
    if [ $? -ne 0 ]; then
	postconf smtpd_tls_mandatory_protocols="${protocols},!SSLv3"
	case $os_name in
	Debian*|Ubuntu*)
		/etc/init.d/postfix restart
	;;
	*)
		service postfix restart
	;;
	esac
    fi
}

fix_courier()
{
	echo "Fix SSL accessible protocols for courier-imap mail server"

	cfg_list="/etc/courier-imap/imapd-ssl /etc/courier-imap/pop3d-ssl"

	flag=0
	for cfg in $cfg_list; do
		[ -f "$cfg" ] || continue

		do_backup $cfg

		flag=1
		grep -q "^[[:space:]]*TLS_CIPHER_LIST" $cfg
		if [ $? -eq 0 ]; then
			sed -i -e "s|^[[:space:]]*TLS_CIPHER_LIST.*|TLS_CIPHER_LIST=\"ALL:!SSLv2:!SSLv3:!ADH:!NULL:!EXPORT:!DES:!LOW:@STRENGTH\"|g" $cfg
			return 0
		else
			echo "TLS_CIPHER_LIST=\"ALL:!SSLv2:!SSLv3:!ADH:!NULL:!EXPORT:!DES:!LOW:@STRENGTH\"" >> $cfg
		fi
	done

	[ $flag -eq 0 ] && return 0

	case $os_name in
	Debian*|Ubuntu*)
		/etc/init.d/courier-imaps restart
                /etc/init.d/courier-pop3s restart
		/etc/init.d/courier-imap restart
	;;
	*)
		service courier-imaps restart
                service courier-pop3s restart
		service courier-imap restart
	;;
	esac
}

fix_dovecot()
{
	echo "Fix SSL accessible protocols for dovecot mail server"

	cfg_list="/etc/dovecot/conf.d/10-plesk-security.conf /etc/dovecot/conf.d/11-plesk-security-pci.conf"

	flag=0
	for cfg in $cfg_list; do
		[ -f "$cfg" ] || continue

		do_backup $cfg

		flag=1
		grep -q "^[[:space:]]*ssl_cipher_list" $cfg
		if [ $? -eq 0 ]; then
			grep -q "^[[:space:]]^ssl_cipher_list.*\!SSLv3" $cfg && return 0
			sed -i -e "s|^\([[:space:]]*ssl_cipher_list.*\)\"|\1:!SSLv3\"|g" $cfg
		else
			echo "ssl_cipher_list = \"HIGH:MEDIUM:!SSLv2:!LOW:!EXP:!aNULL:!ADH:@STRENGTH:!SSLv3\"" >> $cfg
		fi
	done

	[ $flag -eq 0 ] && return 0

	case $os_name in
	Debian*|Ubuntu*)
		/etc/init.d/dovecot restart
	;;
	*)
		service dovecot restart
	;;
	esac
}

fix_proftpd()
{
	echo "Disable SSLv3 for FTP service"

	cfg="/etc/proftpd.d/60-nosslv3.conf"

	echo "<Global>" >$cfg
	echo "<IfModule mod_tls.c>" >>$cfg
	echo "TLSProtocol TLSv1 TLSv1.1 TLSv1.2" >>$cfg
#	echo "TLSCipherSuite ALL:!aNULL:!ADH:!eNULL:!LOW:!EXP:RC4+RSA:+HIGH:+MEDIUM:!SSLv3" >> $cfg
	echo "</IfModule>" >> $cfg
	echo "</Global>" >> $cfg
}

fix_cp_server()
{
    echo "Fix Plesk Panel web service"

    cfg="/etc/sw-cp-server/conf.d/pci-compliance.conf"

    if [ -f "$cfg" ]; then
	do_backup $cfg

	grep -q "^[[:space:]]*ssl_protocols" $cfg
	if [ $? -eq 0 ]; then
		sed -i -e "s|^\([[:space:]]*ssl_protocols\).*|\1		TLSv1 TLSv1.1 TLSv1.2;|" $cfg
	else
		echo  "ssl_protocols           TLSv1 TLSv1.1 TLSv1.2;" >> $cfg
	fi
    else
	echo  "ssl_protocols           TLSv1 TLSv1.1 TLSv1.2;" >> $cfg
	echo  "ssl_ciphers                 HIGH:!aNULL:!MD5;" >> $cfg
	echo  "ssl_prefer_server_ciphers   on;" >> $cfg
    fi

    /etc/init.d/sw-cp-server restart
}

fix_qmail()
{
    echo "Disable SSLv3 in Qmail MTA"
    cfg="/var/qmail/control/tlsserverciphers"

    [ -d "/var/qmail/control" ] || return 0

    echo "ALL:!ADH:!LOW:!SSLv2:!SSLv3:!EXP:+HIGH:+MEDIUM" > $cfg
}

get_os

fix_apache
fix_nginx
fix_postfix
fix_courier
fix_dovecot
fix_proftpd
fix_cp_server
fix_qmail
