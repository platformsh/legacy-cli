<?php

namespace Platformsh\Cli\Util;

class PortUtil
{
    protected static $unsafePorts = [
        1,    // tcpmux
        7,    // echo
        9,    // discard
        11,   // systat
        13,   // daytime
        15,   // netstat
        17,   // qotd
        19,   // chargen
        20,   // ftp data
        21,   // ftp access
        22,   // ssh
        23,   // telnet
        25,   // smtp
        37,   // time
        42,   // name
        43,   // nicname
        53,   // domain
        77,   // priv-rjs
        79,   // finger
        87,   // ttylink
        95,   // supdup
        101,  // hostriame
        102,  // iso-tsap
        103,  // gppitnp
        104,  // acr-nema
        109,  // pop2
        110,  // pop3
        111,  // sunrpc
        113,  // auth
        115,  // sftp
        117,  // uucp-path
        119,  // nntp
        123,  // NTP
        135,  // loc-srv /epmap
        139,  // netbios
        143,  // imap2
        179,  // BGP
        389,  // ldap
        465,  // smtp+ssl
        512,  // print / exec
        513,  // login
        514,  // shell
        515,  // printer
        526,  // tempo
        530,  // courier
        531,  // chat
        532,  // netnews
        540,  // uucp
        556,  // remotefs
        563,  // nntp+ssl
        587,  // stmp?
        601,  // ??
        636,  // ldap+ssl
        993,  // ldap+ssl
        995,  // pop3+ssl
        2049, // nfs
        3659, // apple-sasl / PasswordServer
        4045, // lockd
        6000, // X11
        6665, // Alternate IRC [Apple addition]
        6666, // Alternate IRC [Apple addition]
        6667, // Standard IRC [Apple addition]
        6668, // Alternate IRC [Apple addition]
        6669, // Alternate IRC [Apple addition]
    ];

    /**
     * Get the next available valid port.
     *
     * @param int         $start    The starting port number.
     * @param string|null $hostname The hostname, defaults to 127.0.0.1.
     *
     * @throws \Exception on failure
     *
     * @return int
     */
    public static function getPort($start = 3000, $hostname = null)
    {
        $limit = 30;
        for ($port = $start; $port < $start + $limit; $port++) {
            if (static::validatePort($port) && !static::isPortInUse($port, $hostname)) {
                return $port;
            }
        }
        throw new \Exception('Failed to find a port');
    }

    /**
     * Validate a port number.
     *
     * @param int|string $port
     *
     * @return bool
     */
    public static function validatePort($port)
    {
        if (!is_numeric($port) || $port < 0 || $port > 65535) {
            return false;
        }

        return !in_array($port, self::$unsafePorts);
    }

    /**
     * Check whether a port is open.
     *
     * @param int         $port
     * @param string|null $hostname
     *
     * @return bool
     */
    public static function isPortInUse($port, $hostname = null)
    {
        $fp = @fsockopen($hostname !== null ? $hostname : '127.0.0.1', $port, $errno, $errstr, 10);
        if ($fp !== false) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
