<?php
/**
 * Feste Angaben der Ecovacs-Cloud (Adressen, App-Kennungen). Die App-Schluessel sind die der oeffentlichen Ecovacs-App
 * (so veroeffentlicht in mehreren quelloffenen Projekten); sie sind kein Geheimnis des Benutzers und keine Zugangsdaten.
 * Inoffizielle Schnittstelle: Ecovacs kann sie aendern oder sperren.
 */
final class EcoKonstanten
{
    public const REALM = 'ecouser.net';
    public const USER_AGENT = 'Dalvik/2.1.0 (Linux; U; Android 5.1.1; A5010 Build/LMY48Z)';
    public const APP_VERSION = '2.2.3';             // Anmeldung
    public const APP_VERSION_VERIFY = '3.14.0';     // Geraeteverifizierung
    public const APP_CODE = 'global_e';
    public const CHANNEL = 'google_play';

    /** App-Schluessel (Anmeldung und Authentifizierungscode) fuer Ecovacs. */
    public const LOGIN_APPKEY = '1520391301804';
    public const LOGIN_SECRET = '6c319b2a5cd3e66e39159c2e28f2fce9';
    public const AUTH_APPKEY = '1520391491841';
    public const AUTH_SECRET = '77ef58ce3afbe337da74aa8c5ab963a9';
}
