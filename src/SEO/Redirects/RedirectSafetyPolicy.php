<?php
namespace Yablonsky\PerformanceSEO\SEO\Redirects;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared safety boundary for redirectable public paths.
 *
 * This policy is deliberately conservative. It protects WordPress internals,
 * operational/configuration resources and strong probe signatures from both
 * manual redirect creation and runtime interception. It does not inspect the
 * filesystem and it does not claim that an observed path exists on disk.
 */
final class RedirectSafetyPolicy {
    public const CONTENT = 'content';
    public const TECHNICAL = 'technical';
    public const PROBE = 'probe';

    public static function classifyPath( string $path ): string {
        $path = self::normalizeForCheck( $path );
        if ( '' === $path ) { return self::TECHNICAL; }

        // Exact public media files can legitimately carry links/backlinks and may
        // need an SEO redirect after a move. Directories and code/config paths do not.
        if ( 0 === strpos( $path, '/wp-content/uploads/' ) && preg_match( '#\.(?:avif|gif|ico|jpe?g|mp3|mp4|ogg|pdf|png|svg|wav|webm|webp)$#', $path ) ) {
            return self::CONTENT;
        }

        $probePatterns = array(
            // Dotfiles/dot-directories and credential/config discovery.
            '#(?:^|/)\.(?!well-known(?:/|$))[^/]+#',
            '#(?:^|/)[^/]*\.env(?:[._-][^/]*)?(?:/|$)#',
            '#(?:^|/)(?:wp-config(?:-sample)?\.php(?:\.[^/]*)?|config\.php\.bak|phpinfo(?:\.php)?(?:\.[^/]*)?)(?:/|$)#',
            '#(?:^|/)[^/]+\.(?:bak|backup|key|old|p12|pem|pfx|save|sql)(?:\.[^/]*)?$#',

            // Common build/deployment manifests and framework configuration files.
            '#^/(?:dockerfile|docker-compose(?:\.[^/]+)?\.ya?ml)(?:/|$)#',
            '#(?:^|/)(?:composer(?:\.json|\.lock)?|package(?:-lock)?\.json|pnpm-lock\.ya?ml|yarn\.lock)(?:/|$)#',
            '#^/(?:config(?:\.(?:json|ya?ml))|config/secrets\.ya?ml|application(?:\.[^/]+)?\.(?:properties|ya?ml)|appsettings(?:\.[^/]+)?\.json|server\.key)(?:/|$)#',
            '#^/(?:conf|config)/(?:.+\.(?:ini|json|properties|xml|ya?ml)|secrets(?:/|$))#',

            // API documentation / framework diagnostics / management surfaces.
            '#^/(?:swagger(?:\.json|/)|openapi(?:\.(?:json|ya?ml)|/)|api-docs(?:/|$)|v\d+/api-docs(?:/|$))#',
            '#^/(?:actuator|_ignition|_profiler|debug|phpinfo|telescope)(?:/|$)#',
            '#^/(?:cgi-bin|developmentserver)(?:/|$)#',
            '#^/api/v\d+/projects/?$#',
            '#^/autodiscover/autodiscover\.json$#',
            '#^/\+cscot\+/#',

            // Strong exploit/backdoor scanner signatures seen in the field.
            '#^/(?:firewall|randkeyword|warm|worm)\.php\d*/?$#',
            '#^/\.well-known/[^/]*\.php\d*/?$#',
            '#^/patriotic/wp-includes/#',
            '#^/plugins/jquery\.filer/uploads/?$#',
            '#^/themes/pridmag/?$#',
            '#^/(?:admin/uploads|alfa_data|aspera/faspex|webui)(?:/|$)#',

            // Synthetic requests derived from WordPress script/style handles.
            '#^/(?:config|\.aws|\.docker|\.git)/(?:core-block|global-styles|google_gtagjs|lodash-|moment-|vegan-|wc-|woocommerce-|wp-|width=).+$#',
            '#^/(?:core-block|global-styles|google_gtagjs|lodash-|moment-|vegan-|wc-|woocommerce-|wp-).*(?:inline-css|js-(?:after|before|extra))$#',
            '#^/width=device-width,#',

            // Credential/state/secret artifacts confirmed by the 2026-09-18 Generator.pw field export.
            '#(?:^|/)(?:id_(?:rsa|dsa|ecdsa|ed25519)(?:\.pub)?|authorized_keys|known_hosts|rclone\.conf)(?:/|$)#',
            '#(?:^|/)(?:terraform\.tfstate(?:\.[^/]*)?|terraform\.tfvars|credentials?(?:\.[^/]*)?|service[-_]?account(?:key)?(?:\.[^/]*)?|private[-_]?key(?:\.[^/]*)?)(?:/|$)#',
            '#(?:^|/)(?:aws[-_]?credentials?|gcp[-_]?key|sendgrid[-_]?api[-_]?key|mailgun[-_]?api[-_]?key|smtp[-_]?password)(?:\.[^/]*)?(?:/|$)#',
            '#(?:^|/)(?:secrets?|vault|pki|tls)/(?:.+)$#',
            '#^/(?:etc|proc|root|home|usr|var)/(?:.+)$#',
            '#^/@fs/(?:.+)$#',
            '#^/z9x8c7v6b5-debug-trigger-[^/]+$#',
            '#^/(?:_all_dbs|_cat|_cluster)(?:/|$)#',
            '#^/(?:server-status|server-info)(?:/|$)#',
            '#(?:^|/)[^/]+\.(?:tfstate|tfvars|pgsql|sqlite3?|dump|log|orig|copy|dist)(?:\.[^/]*)?$#',
            '#(?:^|/)(?:gemfile|pipfile)\.lock$#',
            '#(?:^|/)nuget\.config$#',
            '#^/(?:env|env\.txt|wp-config\.txt)$#',
            '#(?:^|/)(?:aws|s3)[^/]*\.(?:properties|secret)$#',
            '#(?:^|/)(?:config|configuration|settings)[^/]*\.(?:cfg|conf|properties|rb|toml|ts)$#',
            '#(?:^|/)(?:secret|token)[^/]*\.(?:cfg|conf|properties|rb|txt)$#',
            '#(?:^|/)(?:backup|html|public|site|source|src|web|www|wwwroot)(?:\.[^/]*)?\.(?:7z|gz|tar|tgz|xz|zip)$#',
            '#^/(?:frontend|sapi|actions)/(?:web/)?debug/default/view/?$#',
            '#^/__debug__(?:/|$)#',
            '#^/_debugbar(?:/|$)#',
            '#^/(?:_snapshot|_search|_settings|_xpack)(?:/|$)#',
            '#^/(?:containers|images)/(?:json|[^/]+/json)$#',
            '#^/networks/v\d+/plugins/?$#',

            // Additional reconnaissance families confirmed by Generator.pw field evidence.
            '#^/(?:mcp|sse)(?:/|$)#',
            '#^/v2/_catalog/?$#',
            '#^/owa(?:/|$)#',
            '#^/rest/api(?:/|$)#',
            '#^/alfacgiapi(?:/|$)#',
            '#^/telerik\.web\.ui\.webresource\.axd$#',
            '#^/solr(?:/|$)#',
            '#^/(?:console|weblogic|jenkins|jmx-console)(?:/|$)#',
            '#^/sitecore(?:/|$)#',
            '#^/(?:hnap1|evox/about|remote/info)(?:/|$)#',
            '#^/nmaplowercheck\d+$#',
            '#^/(?:xleet|dr0v|_environment)(?:/|$)#',
            '#^/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.html$#',
            '#(?:^|/)(?:secret|secrets)\.(?:txt|py|js|json|ya?ml)$#',
            '#(?:^|/)(?:aws[-_]?config|awssettings|aws_config)\.(?:csv|js|json|py|ya?ml)$#',

            // Infrastructure and development artifacts confirmed by the 2026-09-19 field export.
            '#(?:^|/)(?:gemfile|makefile|procfile|jenkinsfile|dockerfile)(?:\.[^/]*)?$#i',
            '#(?:^|/)(?:netlify|vercel)\.toml$#',
            '#(?:^|/)(?:pyproject|cargo|deno|bun|tsconfig)(?:\.[^/]*)?\.(?:json|toml)$#',
            '#(?:^|/)[^/]+\.(?:conf|cfg|properties|secret)(?:[.~_-][^/]*)?$#',
            '#(?:^|/)[^/]+\.(?:swp|tmp|temp)(?:\.[^/]*)?$#',
            '#(?:^|/)(?:db|data|backup)[^/]*\.(?:bz2|gz|xz|zip|tgz|rar|mysql|mongodump|sqlite3?|db)(?:\.[^/]*)?$#',
            '#(?:^|/)(?:api[-_]?key|gcp[-_]?key|azure[-_]?credentials?|smtp[-_]?credentials?|stripe[-_]?secret[-_]?key)(?:[._-][^/]*)?(?:/|$)#',
            '#^/(?:src/main/resources|terraform|cvs|_darcs)(?:/|$)#i',
            '#^/(?:grafana|kibana|prometheus|elasticsearch)(?:/|$)#i',
            '#^/(?:_active_tasks|_config|_nodes|_stats|_utils)(?:/|$)#',
            '#^/sites/default/files(?:/|$)#',

            // Second-pass field signatures from the 2026-09-19 high-cardinality corpus.
            '#(?:^|/)actuator/(?:env|health|info|metrics)(?:/|$)#i',
            '#(?:^|/)(?:requirements(?:\.[^/]+)?\.txt|kubeconfig|prometheus\.txt|env\.list)$#i',
            '#(?:^|/)(?:dump|backup|db_backup)[^/]*\.(?:csv|tsv|json|mysql|sql|db|shared|bz2|gz|xz|zip)(?:\.(?:bz2|gz|xz|zip))?$#i',
            '#(?:^|/)(?:index|admin|login|info)\.php\.(?:\d+|back|new|master|sample|shared)$#i',
            '#(?:^|/)(?:debug|log)\.(?:txt|zip)$#i',
            '#(?:^|/)(?:[a-z0-9._-]+_)?(?:api_?key|secret_?key|mailgun_key|sendgrid_key|mailjet_key|elasticemail_key|algolia_api_key)\.(?:txt|json|ya?ml|env|conf)$#i',
            '#^/(?:k8s|aws-codecommit|gists|drush|icingaweb2|centreon|zabbix)(?:/|$)#i',
            '#^/(?:aws/buckets|rest/(?:oauth1-credential/auth|workflows/run|tags)|json/reply/requestlogs|requestlogs)(?:/|$)#i',
            '#^/(?:swagger-ui\.html|elasticsearch_cluster_yml|phpinfo[^/]*|sitemap\.html)$#i',
            '#^/(?:dev-api|prod-api|staging-api|internal)(?:/|$)#i',
            '#^/(?:pandora(?:_console)?|check_mk|checkmk|nagios(?:xi)?|icinga|opennms|nmconsole)(?:/|$)#i',
            '#^/(?:rails/(?:info|conductor)|manage/actuator|ecp/current/exporttool|apiclient/eme2/api)(?:/|$)#i',
            '#^/(?:rest/(?:workflows|license|variables|credential-translation)|redoc)(?:/|$)#i',
            '#^/(?:core/vendor|wp-content/fonts|media/admin)(?:/|$)#i',
            '#^/(?:aws_s3_bucket|v1/private)(?:/|$)#i',
            '#(?:^|/)(?:config|configuration|database|index\.php)(?:\.[^/]+|~)$#i',
            '#(?:^|/)(?:backup\.(?:txt|bat)|debug-output\.txt|service\.grp)$#i',
            '#(?:^|/)(?:sparkpost|sendgrid|mailgun|mailjet|mandrill|elasticemail)[_-](?:key|keys|config)(?:\.[^/]*)?$#i',
            '#(?:^|/)(?:readme|docs|log)\.md$#i',
            '#(?:^|/)[^/]+\.(?:crt|cer|der)(?:\.[^/]*)?$#i',
            '#(?:^|/)[^/]+\.(?:tar\.gz|txt\.gz)$#i',
            '#^/(?:swagger-ui(?:/|$)|flask/debug|__debugbar|legacy/tests|internal-api|aws/bucket|s3/buckets)(?:/|$)#i',
            '#^/(?:build|themes|include|template|components|php)(?:/|$)#i',
            '#^/(?:_dev|testing|error)(?:/|$)#i',
            '#^/(?:app/info|hc/restricted)(?:/|$)#i',
            '#(?:^|/)(?:gcp-key\d*|azure-credentials~|aws_s3_bucket)(?:/|$)#i',
            '#(?:^|/)(?:application\.ya?ml)(?:\.(?:jpg|sample|bak|old))$#i',
            '#(?:^|/)(?:db|database)\.(?:csv|txt|shared)$#i',
            '#(?:^|/)(?:heapdump|environment|envrc|artisan|devtools|webhook)(?:/|$)#i',
            '#(?:^|/)(?:git/config|php\.ini~|api-key~|gcp-key~|token\.shared|my\.ppk)(?:/|$)#i',
            '#^/(?:static/admin|core/(?:lib|profiles)|rails/mailers|geoserver/web|mysql|test-api|v1/graphql|sapi/status|servlet/json|rest/credentials-for-node)(?:/|$)#i',
            '#^/swagger-ui(?:/|$)#i',
            '#(?:^|/)(?:docker|iam)\.md$#i',
            '#(?:^|/)(?:maintenance\.html|info\.txt|backup\.(?:rust|cs))$#i',
            '#(?:^|/)cmsmessages/[^/]+\.aspx\.cs$#i',
            '#(?:^|/)db/admin/settings/logs\.txt$#i',
            '#^/(?:tokens|reportserver)(?:/|$)#i',

            // Plain redirect origins must never behave like wildcard rules.
            '#\*#',
        );
        foreach ( $probePatterns as $pattern ) {
            if ( preg_match( $pattern, $path ) ) { return self::PROBE; }
        }

        $technicalPatterns = array(
            // WordPress internals / executable surfaces.
            '#^/(?:wp-admin|wp-includes|wp-json)(?:/|$)#',
            '#^/(?:wp-activate\.php|wp-comments-post\.php|wp-cron\.php|wp-load\.php|wp-login\.php|wp-mail\.php|wp-settings\.php|wp-signup\.php|wp-trackback\.php|xmlrpc\.php)(?:/|$)#',
            '#^/wp-content/(?:cache|languages|mu-plugins(?:-old)?|plugins|themes|upgrade)(?:/|$)#',
            '#^/wp-content/uploads(?:/|$)#',

            // Public machine endpoints are operational, not SEO content routes.
            '#^/\.well-known(?:/|$)#',
            '#^/(?:ads|robots)\.txt$#',
            '#(?:^|/)[^/]*sitemap[^/]*\.xml(?:/|$)#',
            '#^/(?:api|graphql|ipfs)(?:/|$)#',
            '#(?:^|/)[^/]+\.(?:ini|json|xml|ya?ml)$#',
            '#\.(?:js|css|py|rb|ts|php\d*|phar|aspx?|axd|jsp|do|cgi|pl|sh|ps1|cc)(?:~)?$#',

            // Machine/framework endpoints observed as non-content 404 noise.
            '#^/(?:llms\.txt|manifest\.webmanifest|livewire/update|__vite_rsc_findSourceMapURL)(?:/|$)#i',
            '#^/(?:metrics|healthz?|dns-query)(?:/|$)#',
            '#^/(?:ip|info|status|system|version|query|resolve|ws|mon|monitor|monitoring|ueberwachung|kontrolle|healthcheck|readiness|ready)/?$#',
            '#(?:^|/)\[(?:\.\.\.)?[^/\]]+\](?:/|$)|(?:^|/)\[\[\.\.\.[^/\]]+\]\](?:/|$)#',
            '#(?:^|/)[^/]+\.(?:config|tf|mjs|cjs|java|go|lua|gradle|prisma|plist|service|bash|perl|swift|kotlin)(?:[.~_-][^/]*)?$#',

            // Common non-content namespaces. These can be reviewed diagnostically,
            // but YSEO should not rewrite them as front-end SEO content.
            '#^/(?:admin|assets|backup|dashboard|id|index|login|modules|plugins|upload|uploads|users/sign_in|web|webinterface|wordpress|wp|www)(?:/|$)#',
            '#^/(?:conf|config|deploy|node_modules|server|storage|vendor)(?:/|$)#',
        );
        foreach ( $technicalPatterns as $pattern ) {
            if ( preg_match( $pattern, $path ) ) { return self::TECHNICAL; }
        }

        return self::CONTENT;
    }

    public static function isProtectedPath( string $path ): bool {
        return self::CONTENT !== self::classifyPath( $path );
    }

    private static function normalizeForCheck( string $path ): string {
        $path = trim( $path );
        if ( '' === $path ) { return ''; }
        if ( preg_match( '#^https?://#i', $path ) ) {
            $parts = wp_parse_url( $path );
            if ( ! is_array( $parts ) ) { return ''; }
            $path = (string) ( $parts['path'] ?? '/' );
        }
        $path = rawurldecode( $path );
        if ( false !== strpos( $path, "\0" ) ) { return ''; }
        $path = '/' . ltrim( preg_replace( '#/+#', '/', $path ) ?: '', '/' );
        if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) ) { return ''; }
        return strtolower( $path );
    }
}
