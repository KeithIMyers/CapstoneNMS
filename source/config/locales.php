<?php

/*
 * Public-site supported locales. BCP-47 codes as keys; values are shown
 * in the language switcher and in hreflang alternates.
 *
 * The default locale ('en') is served at the canonical URL (no prefix).
 * All other locales are served under /{locale}/…
 *
 * To add a locale: append here, create the article translation in admin,
 * and clear the response cache. No further code changes needed.
 */
return [
    'default' => 'en',
    'supported' => [
        'en'       => 'English',
        'es'       => 'Español',
        'zh-Hans'  => '简体中文',
        'fr'       => 'Français',
        'de'       => 'Deutsch',
    ],
];
