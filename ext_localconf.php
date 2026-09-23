<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Names the runs of the Word import in webcon_jev's run log (Jev chooses the content type of new
// elements when webcon_jev is installed). Harmless without webcon_jev: nobody reads the entry.
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['webcon_jev']['runContexts']['docx_editor_page_import']
    = 'LLL:EXT:docx_editor/Resources/Private/Language/locallang_pagesync.xlf:jev.context';
