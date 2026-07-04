<?php

$ru = require lang_path('ru/miniapp.php');

return array_replace_recursive($ru, [
    'title_suffix' => 'Me and my household', 'default_family_name' => 'Our family', 'default_family_subtitle' => 'Family history and ancestral memory', 'unassociated_photo' => 'Unassociated photo', 'pair_names' => ':one and :two', 'family_member' => 'Family member', 'crest_alt' => 'Family crest', 'manage' => 'Manage', 'logout' => 'Sign out', 'sections' => 'Sections',
    'tabs' => ['tree' => 'Tree', 'list' => 'List', 'gallery' => 'Photos', 'birthdays' => 'Birthdays', 'events' => 'Events', 'me' => 'My family'],
    'filters' => [
        'heading' => 'Search and filters', 'close' => 'Close filters', 'search' => 'Find a person…', 'search_label' => 'Search',
        'gender' => 'Gender', 'gender_all' => 'Any gender', 'women' => 'Women', 'men' => 'Men', 'place' => 'Place', 'places_all' => 'All places',
        'status' => 'Status', 'all' => 'All', 'living' => 'Living', 'deceased' => 'Deceased', 'depth' => 'Branch depth',
        'generation_1' => '1 generation', 'generation_2' => '2 generations', 'generation_3' => '3 generations', 'generation_4' => '4 generations',
        'relation' => 'Relationship to me', 'relatives_all' => 'All relatives', 'parents' => 'My parents', 'grandparents' => 'My grandparents',
        'spouses' => 'My partner', 'children' => 'My children', 'grandchildren' => 'My grandchildren', 'siblings' => 'My siblings', 'nephews' => 'My nieces and nephews',
        'reset' => 'Reset filters', 'apply' => 'Done',
    ],
    'tree' => ['label' => 'Family tree', 'zoom_out' => 'Zoom out', 'zoom_in' => 'Zoom in', 'fit' => 'Fit branch', 'mine' => 'My branch', 'all' => 'Whole tree', 'empty' => 'No one found', 'empty_hint' => 'Try changing the filters.'],
    'birthdays_intro' => 'Upcoming birthdays and anniversaries', 'gallery_more' => 'Show more', 'events_title' => 'Family events', 'events_archive' => 'Past events', 'loading' => 'Loading',
    'issue' => ['button' => 'Report an issue', 'title' => 'Report an issue', 'text' => 'Describe what should be checked or corrected.', 'subject' => 'Briefly describe the issue', 'details' => 'Details', 'send' => 'Send to the tree owner'],
    'congratulation' => ['title' => 'Send congratulations', 'message' => 'Write a warm message', 'send' => 'Send congratulations'],
    'auth' => ['title' => 'Sign in to the family archive', 'text' => 'Use Telegram or a personal login provided by the administrator.', 'telegram' => 'Sign in with Telegram', 'or' => 'or', 'login' => 'Username', 'password' => 'Password', 'submit' => 'Sign in', 'credentials' => 'Get a username and password in Telegram'],
    'js' => [
        'server_error' => 'Server error. Refresh the page or try again later.', 'load_error' => 'Could not load data', 'telegram_login' => 'Sign in with Telegram', 'born' => 'b. :date',
        'relations' => ['self' => 'This is you', 'parents' => 'Parent', 'grandparents' => 'Grandparent', 'spouses' => 'Partner', 'children' => 'Child', 'grandchildren' => 'Grandchild', 'siblings' => 'Sibling', 'nephews' => 'Niece / nephew', 'relative' => 'Relative'],
        'empty_filter' => 'No one matches this filter.', 'fields' => ['birth_date' => 'Date of birth', 'death_date' => 'Date of death', 'life_years' => 'Years of life', 'maiden_name' => 'Maiden name', 'birth_place' => 'Place of birth', 'death_place' => 'Place of death', 'burial_place' => 'Burial place', 'city' => 'City', 'address' => 'Address', 'occupation' => 'Occupation', 'parents' => 'Parents', 'spouses' => 'Partners', 'children' => 'Children', 'photos' => 'Photos'],
        'show_branch' => 'Show family branch', 'wrong_tree' => 'The server returned another family tree. Refresh the page.', 'birthdays' => 'Birthdays', 'shown' => 'Showing :shown of :total',
        'stale' => 'Could not refresh. Showing the most recent loaded version.', 'all_places' => 'All places', 'years' => ':count years', 'today' => 'today', 'in_days' => 'in :count days', 'congratulate' => 'Congratulate',
        'no_birthdays' => 'No birthdays have been added yet.', 'anniversaries' => 'Anniversaries', 'received' => 'Received congratulations',
        'birthday_wish' => 'Happy birthday! Wishing you health, joy and family warmth!', 'anniversary_wish' => 'Happy anniversary! Wishing you love, harmony and many happy years together!',
        'no_photos' => 'No photos yet.', 'annual' => 'annual', 'no_events' => 'No events yet.', 'family_photo' => 'Family photo', 'open_person' => 'Open :name’s profile',
        'sent_telegram' => 'Saved and sent through Telegram: :count.', 'saved_site' => 'Saved on the family website. Telegram is not connected or unavailable.', 'sending' => 'Sending…',
        'editor' => [
            'last_name' => 'Last name', 'first_name' => 'First name', 'middle_name' => 'Middle name', 'maiden_name' => 'Maiden name',
            'gender' => 'Gender', 'gender_unknown' => 'Not specified', 'gender_male' => 'Male', 'gender_female' => 'Female',
            'current_city' => 'Current city', 'biography' => 'Biography', 'spouse' => 'Partner', 'child' => 'Child', 'grandchild' => 'Grandchild', 'child_spouse' => 'Child’s partner',
            'readonly' => 'You have guest access. Data is read-only.', 'your_profile' => 'Your profile in the family archive', 'save_profile' => 'Save my details', 'my_branch' => 'My family branch',
            'save' => 'Save', 'unlink' => 'Remove relationship', 'empty_relatives' => 'No relatives have been added yet.', 'add_relative' => 'Add a relative', 'relative_kind' => 'Who are you adding?',
            'add_spouse' => 'Partner', 'add_child' => 'Child', 'add_grandchild' => 'Grandchild', 'add_child_spouse' => 'Child’s partner',
            'through_child' => 'Through which child?', 'not_required' => 'Not required', 'add_tree' => 'Add to tree',
            'albums' => 'Photo albums', 'delete' => 'Delete', 'no_albums' => 'No albums yet.', 'album_title' => 'New album title', 'create' => 'Create',
            'my_photos' => 'My photos', 'photo_caption' => 'Photo caption', 'no_album' => 'No album', 'make_primary' => 'Make primary', 'upload' => 'Upload photo', 'primary' => 'Primary', 'first_photo' => 'Upload your first photo.',
            'delete_profile' => 'Delete my profile', 'delete_profile_text' => 'The profile will be hidden and Telegram unlinked. Enter “DELETE”.', 'delete_profile_button' => 'Delete my profile',
            'personal_data' => 'My personal data', 'personal_data_text' => 'Download all account data or permanently delete the account.', 'download_data' => 'Download my data',
            'delete_account_placeholder' => 'DELETE ACCOUNT', 'delete_account' => 'Delete account', 'confirm_unlink' => 'Remove this family relationship? The person’s profile will remain.',
            'confirm_album' => 'Delete the album? Photos will remain.', 'confirm_photo' => 'Delete this photo?', 'confirm_profile' => 'This will delete your profile. Continue?',
        ],
    ],
]);
