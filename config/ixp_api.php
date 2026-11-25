<?php

/*
 * Copyright (C) 2009 - 2019 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GpNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

// API config options for IXP Manager

return [

    /*
    |--------------------------------------------------------------------------
    | JSON Export Schema
    |--------------------------------------------------------------------------
    |
    */
    'json_export_schema' => [

        // if false, an API key is required
        'public' => env( 'IXP_API_JSONEXPORTSCHEMA_PUBLIC', true ),

        // or - we can set a static key here if we like:
        'access_key' => env( 'IXP_API_JSONEXPORTSCHEMA_ACCESS_KEY', false ),

        // some IXs want to exclude some information:
        'excludes' => [
            'rfc5398'   => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_RFC5398', true    ),
            'rfc6996'   => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_RFC6996', true    ),
            'tags'      => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_TAGS',    false   ),
            'asnum'     => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_ASNUM',   false   ),
            'switch'    => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_SWITCH',  false   ),
            'ixp'       => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_IXP',     false   ),
            'member'    => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_MEMBER',  false   ),
            'intinfo'   => env( 'IXP_API_JSONEXPORTSCHEMA_EXCLUDE_INTINFO', false   ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RIR details for updating RIR AS/AS-SET objects
    |--------------------------------------------------------------------------
    |
    */
    'rir' => [
        'password' => env( 'IXP_API_RIR_PASSWORD', 'xxxxx' ),
        
        'email'    => [
            'from' => env( 'IXP_API_RIR_EMAIL_FROM', null ),
            'to'   => env( 'IXP_API_RIR_EMAIL_TO',   null ),
        ],
        
        'ripe_api_key' => env( 'IXP_RIPE_API_KEY', null ),
    ],


    /*
    |--------------------------------------------------------------------------
    | Nagios Configuration Generation
    |--------------------------------------------------------------------------
    |
    */
    'nagios' => [
        'birdseye_check'  => env( 'IXP_API_NAGIOS_BIRDSEYE_CHECK',
                                    "/usr/local/nagios-plugins-other/nagios-check-birdseye.php" ),
        'birdseye_bgp_session_check' => env( 'IXP_API_NAGIOS_BIRDSEYE_BGP_SESSION_CHECK',
                                    "/usr/local/nagios-plugins-other/nagios-check-birdseye-bgp-sessions.php" ),
        
        'infra_host'        => env( 'IXP_API_NAGIOS_INFRA_HOST',       "generic-host"    ),
        'customer_host'     => env( 'IXP_API_NAGIOS_CUSTOMER_HOST',    "generic-host"    ),
        'infra_service'     => env( 'IXP_API_NAGIOS_INFRA_SERVICE',    "generic-service" ),
        'customer_service'  => env( 'IXP_API_NAGIOS_CUSTOMER_SERVICE', "generic-service" ),
    ],

    /*
    |--------------------------------------------------------------------------
    | PEERING DB
    |--------------------------------------------------------------------------
    |
    */
    'peeringDB' => [
        'api-key'         => env( 'IXP_API_PEERING_DB_API_KEY',  null ),
        // you should not need to change this. The %s is either "$un:$pw@" or an empty string
        'url'             => env( 'IXP_API_PEERING_DB_URL',      "https://www.peeringdb.com/api" ),

        'ixp_www'         => env( 'IXP_WWW_PEERING_DB_IXP_URL',  "https://www.peeringdb.com/ix/%%COL%%"  ),

        'api_cache_ttl'   => 3600,      // how long to cache /ix results
    ],

    /*
    |--------------------------------------------------------------------------
    | IXP Manager .org
    |--------------------------------------------------------------------------
    |
    */
    'ixp-manager-dotorg' => [
        'base_url' => env( 'IXP_MANAGER_DOTORG_BASE_URL', "https://www.ixpmanager.org" ),
    ],


    /*
    |--------------------------------------------------------------------------
    | IXP DB
    |--------------------------------------------------------------------------
    |
    */
    'IXPDB' => [
        'ixp_api'         => env( 'IXP_API_IXPDB_IXP_URL',  "https://api.ixpdb.net/v1/provider/list"  ),
        'ixp_www'         => env( 'IXP_WWW_IXPDB_IXP_URL',  "https://ixpdb.euro-ix.net/en/explore/ixp/%%COL%%/" ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Whois servers
    |--------------------------------------------------------------------------
    |
    */

    'whois' => [
        // all responses cached for:
        'cache_ttl' => env( 'IXP_API_WHOIS_CACHE_TTL', 60 * 60 * 12 ),

        'asn' => [
            'host' => env( 'IXP_API_WHOIS_ASN_HOST',    'whois.peeringdb.com' ),
            'port' => env( 'IXP_API_WHOIS_ASN_PORT',    43 ),
        ],

        'asn2' => [
            'host' => env( 'IXP_API_WHOIS_ASN2_HOST',    'whois.cymru.com' ),
            'port' => env( 'IXP_API_WHOIS_ASN2_PORT',    43 ),
        ],

        'prefix' => [
            'host' => env( 'IXP_API_WHOIS_PREFIX_HOST', 'bgp.tools' ),
            'port' => env( 'IXP_API_WHOIS_PREFIX_PORT', 43 ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ripe Atlas
    |--------------------------------------------------------------------------
    |
    */
    'atlas_measurement_key' => env( 'ATLAS_MEASUREMENT_KEY', '' ),
    
    
    /*
    |--------------------------------------------------------------------------
    | Unsecured API paths
    |--------------------------------------------------------------------------
    |
    | IXP Manager v7.1.0 introduced an admin/ prepend on APIs for securing them.
    |
    | The default was switched from true to false in v7.2.0.
    |
    | See: https://docs.ixpmanager.org/latest/install/security/
    */

    'unsecured_api_access' => env( 'UNSECURED_API_ACCESS', false ),

    /*
    |--------------------------------------------------------------------------
    | Allow API Keys to be provided via GET parameter in URL.
    |--------------------------------------------------------------------------
    |
    | This previously supported authentication mode is being deprecated in favour of
    | providing the key via HTTP Headers. A log will be generated when an API Key uses
    | this feature.
    |
    | The setting defaults to off as of v7.4.0.
    |
    | See: https://docs.ixpmanager.org/latest/features/api/
    */
    'allow_apikeys_get_parameter' => env( 'IXP_ALLOW_DEPRECATED_APIKEYS_VIA_GET', false ),

    /*
    |--------------------------------------------------------------------------
    | Provisioning API
    |--------------------------------------------------------------------------
    |
    | Endpoints under admin/api/v4/provisioning which create and change members,
    | users and connections from an external system such as an ordering platform.
    |
    | These are the only endpoints in IXP Manager which let an external caller
    | create business objects, so they are OFF by default. An installation which
    | does not switch them on does not have them: the routes are never registered.
    |
    | See: docs/provisioning-api.md
    |
    */

    'provisioning' => [

        /*
        | Master switch. Off by default - turning it on is a deliberate decision by
        | the operator, not something an upgrade does to them.
        */
        'enabled' => env( 'IXP_API_PROVISIONING_ENABLED', false ),

        /*
        | Require a real API key.
        |
        | The `api/v4` middleware group starts a session, and ApiAuthenticate only
        | looks for a key when Auth::check() is false. Without this, a browser
        | already logged in as a superuser reaches these endpoints on its session
        | cookie - and, because the group deliberately omits `web`, without a CSRF
        | token either.
        |
        | For endpoints which create members that is not acceptable, so the default
        | is to refuse ambient browser credentials and insist on a key.
        */
        'require_api_key' => env( 'IXP_API_PROVISIONING_REQUIRE_KEY', true ),

        /*
        | Restrict by source address.
        |
        | Two independent layers, both optional:
        |
        |   - this list applies to every provisioning request
        |   - api_keys.allowed_ips applies per key (a column which has existed for
        |     years but was never enforced anywhere; these endpoints honour it)
        |
        | Comma-separated addresses or CIDR ranges, IPv4 and IPv6:
        |   IXP_API_PROVISIONING_ALLOWED_IPS="192.0.2.10,198.51.100.0/24,2001:db8::/48"
        |
        | Empty means no restriction at this layer. Note that a restriction here is
        | not a substitute for one at the web server, which is where the /admin
        | prefix introduced in v7.1.0 is meant to be enforced - it is a second lock
        | on the same door.
        |
        | The address checked is the one Laravel reports for the request, and IXP
        | Manager configures no trusted proxies. Behind a reverse proxy on another
        | host every request therefore appears to come from the proxy: list the
        | proxy here only if it admits nobody but the calling system, or configure
        | trusted proxies first.
        */
        'allowed_ips' => env( 'IXP_API_PROVISIONING_ALLOWED_IPS', '' ),

        /*
        | Requests per minute per user - the owner of the API key, so several keys
        | of one user share the limit. Zero disables throttling.
        */
        'rate_limit' => (int) env( 'IXP_API_PROVISIONING_RATE_LIMIT', 60 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing webhooks
    |--------------------------------------------------------------------------
    |
    | IXP Manager tells an external system about changes to
    | members, users, contacts, connections, switch ports and notes - whoever
    | made them: staff or members in the web UI, the provisioning API, artisan
    | commands or the scheduler. Changes are recorded in an outbox inside the
    | writing transaction and delivered after commit, signed per Standard
    | Webhooks, with retries.
    |
    | OFF by default: nothing is recorded or sent until this is switched on.
    |
    | See: docs/fragments/w1b-hooks.md
    |
    */

    'webhooks' => [

        /*
        | Master switch. While false, no change is recorded and nothing is sent.
        */
        'enabled' => env( 'IXP_WEBHOOKS_ENABLED', false ),

        /*
        | Where events go. The endpoint built from the IXP_WEBHOOKS_* keys is
        | called `default`; a second receiver is one more entry here, with its
        | own URL, secrets and filters.
        |
        |   url              https URL events are POSTed to. An endpoint without
        |                    a URL does not exist.
        |   secret           whsec_<base64 of at least 24 random bytes>
        |   secret_previous  the secret being rotated out: while set, every
        |                    request carries a signature with each of the two
        |   events           comma-separated globs of event types to send, e.g.
        |                    "member.*,connection.*"
        |   exclude_events   comma-separated globs of event types not to send
        |   skip_api_keys    comma-separated api_keys ids or token identifiers
        |                    whose own changes are recorded but not sent to this
        |                    endpoint (loop prevention)
        */
        'endpoints' => [
            'default' => [
                'url'               => env( 'IXP_WEBHOOKS_URL', '' ),
                'secret'            => env( 'IXP_WEBHOOKS_SECRET', '' ),
                'secret_previous'   => env( 'IXP_WEBHOOKS_SECRET_PREVIOUS', '' ),
                'events'            => env( 'IXP_WEBHOOKS_EVENTS', '*' ),
                'exclude_events'    => env( 'IXP_WEBHOOKS_EXCLUDE_EVENTS', '' ),
                'skip_api_keys'     => env( 'IXP_WEBHOOKS_SKIP_API_KEYS', '' ),
            ],
        ],

        /*
        | Delivery attempts per event, the first included, and the age after
        | which an undelivered event is given up (dead-lettered) regardless.
        */
        'max_attempts'      => (int) env( 'IXP_WEBHOOKS_MAX_ATTEMPTS', 11 ),
        'max_age_hours'     => (int) env( 'IXP_WEBHOOKS_MAX_AGE_HOURS', 72 ),

        /*
        | Seconds to wait for the receiver: to connect, and for the whole request.
        */
        'timeout'           => (int) env( 'IXP_WEBHOOKS_TIMEOUT', 10 ),
        'connect_timeout'   => (int) env( 'IXP_WEBHOOKS_CONNECT_TIMEOUT', 3 ),

        /*
        | Days to keep events whose deliveries are finished (delivered, given up
        | or skipped). webhooks:prune runs daily.
        */
        'retention_days'    => (int) env( 'IXP_WEBHOOKS_RETENTION_DAYS', 30 ),

        /*
        | Try to deliver at the end of the request or command which made the
        | change, after the response has been sent; otherwise the scheduler's
        | per-minute webhooks:deliver run delivers.
        |
        |   auto   for commands, and for requests under PHP-FPM or LiteSpeed,
        |          which release the client first. Not under Apache's mod_php,
        |          where the client would wait for the attempt.
        |   true   always - also under mod_php
        |   false  never: the scheduler only
        */
        'deliver_after_response' => env( 'IXP_WEBHOOKS_DELIVER_AFTER_RESPONSE', 'auto' ),

        /*
        | Record changes made by artisan commands, the scheduler and queue
        | workers (SNMP-discovered ports, user:create, ...), not only those
        | made over HTTP.
        */
        'include_console'   => env( 'IXP_WEBHOOKS_INCLUDE_CONSOLE', true ),

        /*
        | Plain http:// endpoints are refused unless this is true. For a
        | development receiver only: the payloads carry personal data.
        */
        'allow_http'        => env( 'IXP_WEBHOOKS_ALLOW_HTTP', false ),
    ],
];
