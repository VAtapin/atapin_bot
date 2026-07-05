<?php

$ru = require lang_path('ru/miniapp.php');

return array_replace_recursive($ru, [
    'title_suffix' => 'Ich und mein Haus', 'default_family_name' => 'Unsere Familie', 'default_family_subtitle' => 'Familiengeschichte und Erinnerung der Generationen', 'unassociated_photo' => 'Foto ohne Zuordnung', 'pair_names' => ':one und :two', 'family_member' => 'Familienmitglied', 'crest_alt' => 'Familienwappen', 'manage' => 'Verwalten', 'logout' => 'Abmelden', 'sections' => 'Bereiche',
    'tabs' => ['tree' => 'Stammbaum', 'list' => 'Liste', 'gallery' => 'Fotos', 'birthdays' => 'Geburtstage', 'events' => 'Ereignisse', 'me' => 'Meine Familie'],
    'filters' => [
        'heading' => 'Suche und Filter', 'close' => 'Filter schließen', 'search' => 'Person suchen…', 'search_label' => 'Suche',
        'gender' => 'Geschlecht', 'gender_all' => 'Alle Geschlechter', 'women' => 'Frauen', 'men' => 'Männer', 'place' => 'Ort', 'places_all' => 'Alle Orte',
        'status' => 'Status', 'all' => 'Alle', 'living' => 'Lebend', 'deceased' => 'Verstorben', 'depth' => 'Tiefe des Zweigs',
        'generation_1' => '1 Generation', 'generation_2' => '2 Generationen', 'generation_3' => '3 Generationen', 'generation_4' => '4 Generationen',
        'relation' => 'Verwandtschaft zu mir', 'relatives_all' => 'Alle Verwandten', 'parents' => 'Meine Eltern', 'grandparents' => 'Meine Großeltern',
        'spouses' => 'Mein Partner', 'children' => 'Meine Kinder', 'grandchildren' => 'Meine Enkel', 'siblings' => 'Meine Geschwister', 'nephews' => 'Meine Nichten und Neffen',
        'reset' => 'Filter zurücksetzen', 'apply' => 'Fertig',
    ],
    'tree' => ['label' => 'Familienstammbaum', 'zoom_out' => 'Verkleinern', 'zoom_in' => 'Vergrößern', 'fit' => 'Zweig einpassen', 'mine' => 'Mein Zweig', 'all' => 'Ganzer Stammbaum', 'empty' => 'Niemand gefunden', 'empty_hint' => 'Ändern Sie die Filter.'],
    'birthdays_intro' => 'Nächste Geburtstage und Jahrestage', 'gallery_more' => 'Mehr anzeigen', 'events_title' => 'Familienereignisse', 'events_archive' => 'Vergangene Ereignisse', 'loading' => 'Laden',
    'issue' => ['button' => 'Fehler melden', 'title' => 'Fehler melden', 'text' => 'Beschreiben Sie, was geprüft oder korrigiert werden soll.', 'subject' => 'Kurze Fehlerbeschreibung', 'details' => 'Details', 'send' => 'An den Eigentümer senden'],
    'congratulation' => ['title' => 'Gratulieren', 'message' => 'Schreiben Sie persönliche Grüße', 'send' => 'Glückwunsch senden'],
    'auth' => ['title' => 'Beim Familienarchiv anmelden', 'text' => 'Verwenden Sie Telegram oder persönliche Zugangsdaten.', 'telegram' => 'Mit Telegram anmelden', 'or' => 'oder', 'login' => 'Benutzername', 'password' => 'Passwort', 'submit' => 'Anmelden', 'credentials' => 'Zugangsdaten in Telegram erhalten'],
    'js' => [
        'server_error' => 'Serverfehler. Laden Sie die Seite neu oder versuchen Sie es später.', 'load_error' => 'Daten konnten nicht geladen werden', 'telegram_login' => 'Mit Telegram anmelden', 'born' => 'geb. :date',
        'relations' => ['self' => 'Das sind Sie', 'parents' => 'Elternteil', 'grandparents' => 'Großelternteil', 'spouses' => 'Partner', 'children' => 'Kind', 'grandchildren' => 'Enkelkind', 'siblings' => 'Geschwister', 'nephews' => 'Nichte / Neffe', 'relative' => 'Verwandte Person'],
        'empty_filter' => 'Keine Person entspricht dem Filter.', 'fields' => ['birth_date' => 'Geburtsdatum', 'death_date' => 'Sterbedatum', 'life_years' => 'Lebensjahre', 'maiden_name' => 'Geburtsname', 'birth_place' => 'Geburtsort', 'death_place' => 'Sterbeort', 'burial_place' => 'Bestattungsort', 'city' => 'Ort', 'address' => 'Adresse', 'occupation' => 'Beruf', 'parents' => 'Eltern', 'spouses' => 'Partner', 'children' => 'Kinder', 'photos' => 'Fotos'],
        'show_branch' => 'Familienzweig anzeigen', 'wrong_tree' => 'Der Server hat einen anderen Stammbaum geliefert. Laden Sie die Seite neu.', 'birthdays' => 'Geburtstage', 'shown' => ':shown von :total angezeigt',
        'stale' => 'Aktualisierung fehlgeschlagen. Die zuletzt geladene Version wird angezeigt.', 'all_places' => 'Alle Orte', 'years' => ':count Jahre', 'today' => 'heute', 'in_days' => 'in :count Tagen', 'congratulate' => 'Gratulieren',
        'add_calendar' => 'Zum Kalender', 'birthday_calendar_title' => 'Geburtstag: :name', 'anniversary_calendar_title' => 'Jahrestag: :name',
        'no_birthdays' => 'Noch keine Geburtstage vorhanden.', 'anniversaries' => 'Jahrestage', 'received' => 'Erhaltene Glückwünsche',
        'birthday_wish' => 'Herzlichen Glückwunsch zum Geburtstag! Gesundheit, Freude und viel Familienglück!', 'anniversary_wish' => 'Herzlichen Glückwunsch zum Jahrestag! Viel Liebe, Harmonie und viele gemeinsame Jahre!',
        'no_photos' => 'Noch keine Fotos.', 'annual' => 'jährlich', 'no_events' => 'Noch keine Ereignisse.', 'family_photo' => 'Familienfoto', 'open_person' => 'Profil von :name öffnen',
        'sent_telegram' => 'Gespeichert und über Telegram gesendet: :count.', 'saved_site' => 'Auf der Familienseite gespeichert. Telegram ist nicht verbunden oder nicht erreichbar.', 'sending' => 'Wird gesendet…',
        'editor' => [
            'last_name' => 'Nachname', 'first_name' => 'Vorname', 'middle_name' => 'Vatersname', 'maiden_name' => 'Geburtsname',
            'gender' => 'Geschlecht', 'gender_unknown' => 'Nicht angegeben', 'gender_male' => 'Männlich', 'gender_female' => 'Weiblich',
            'current_city' => 'Wohnort', 'biography' => 'Biografie', 'spouse' => 'Partner', 'child' => 'Kind', 'grandchild' => 'Enkelkind', 'child_spouse' => 'Partner des Kindes',
            'readonly' => 'Sie haben Gastzugriff. Die Daten können nur angesehen werden.', 'your_profile' => 'Ihr Profil im Familienarchiv', 'save_profile' => 'Meine Daten speichern', 'my_branch' => 'Mein Familienzweig',
            'save' => 'Speichern', 'unlink' => 'Beziehung entfernen', 'empty_relatives' => 'Noch keine Angehörigen hinzugefügt.', 'add_relative' => 'Verwandte Person hinzufügen', 'relative_kind' => 'Wen möchten Sie hinzufügen?',
            'add_spouse' => 'Partner', 'add_child' => 'Kind', 'add_grandchild' => 'Enkelkind', 'add_child_spouse' => 'Partner des Kindes',
            'through_child' => 'Über welches Kind?', 'not_required' => 'Nicht erforderlich', 'add_tree' => 'Zum Stammbaum hinzufügen',
            'albums' => 'Fotoalben', 'delete' => 'Löschen', 'no_albums' => 'Noch keine Alben.', 'album_title' => 'Titel des neuen Albums', 'create' => 'Erstellen',
            'my_photos' => 'Meine Fotos', 'photo_caption' => 'Bildunterschrift', 'no_album' => 'Ohne Album', 'make_primary' => 'Als Hauptfoto verwenden', 'upload' => 'Foto hochladen', 'primary' => 'Hauptfoto', 'first_photo' => 'Laden Sie das erste Foto hoch.',
            'delete_profile' => 'Mein Profil löschen', 'delete_profile_text' => 'Das Profil wird ausgeblendet und Telegram getrennt. Geben Sie „LÖSCHEN“ ein.', 'delete_profile_button' => 'Mein Profil löschen',
            'personal_data' => 'Meine personenbezogenen Daten', 'personal_data_text' => 'Kontodaten herunterladen oder das Konto vollständig löschen.', 'download_data' => 'Meine Daten herunterladen',
            'delete_account_placeholder' => 'KONTO LÖSCHEN', 'delete_account' => 'Konto löschen', 'confirm_unlink' => 'Familienbeziehung entfernen? Das Profil bleibt im Archiv.',
            'confirm_album' => 'Album löschen? Die Fotos bleiben erhalten.', 'confirm_photo' => 'Foto löschen?', 'confirm_profile' => 'Ihr Profil wird wirklich gelöscht. Fortfahren?',
        ],
    ],
]);
