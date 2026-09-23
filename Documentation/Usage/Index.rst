..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Open and edit
=============

#.  Open :guilabel:`Media` > :guilabel:`Filelist` and browse to a `.docx` file.
#.  Choose the :guilabel:`Edit DOCX` action.
#.  Edit in the full-page editor. The style dropdown offers
    :guilabel:`Normal` and :guilabel:`Heading 1`–:guilabel:`Heading 4`; the same
    headings are available as H1–H4 buttons at the start of the toolbar.

Save
====

-   :guilabel:`Save` in the DocHeader or :kbd:`Ctrl+S` / :kbd:`Cmd+S` writes the
    document back to the same FAL file.
-   The Save dropdown offers :guilabel:`Save and close` and :guilabel:`Save as…`.
    :guilabel:`Save as…` opens the folder browser, then a dialog for the file
    name (``.docx`` is appended), and the editor reopens the new file. An
    existing name gets a ``_01`` suffix.
-   :guilabel:`Close` returns to the folder in the file list. With unsaved
    changes it asks first — keep editing, discard the changes, or save and
    close — like record editing does; leaving the page any other way lets the
    browser warn.
-   :guilabel:`Download` fetches the stored file.
-   Feedback arrives as backend notifications ("Saved to fileadmin / …"),
    error messages in the language of the backend user.

Permissions
===========

Read permission on storage and file opens the document in viewing mode; write
permission enables saving. The :guilabel:`Edit DOCX` action is only shown for
readable files.

Working together
================

A badge next to the heading shows how many editors currently have the file
open; hover it for their names. Every save increments a revision counter; if
someone else saved while you were editing, your save is rejected and a warning
callout offers :guilabel:`Reload document`.

Light and dark mode
===================

The editor follows the colour scheme of the backend: its title bar, toolbar,
menus and dialogs use TYPO3's design tokens. The document page stays white, as
Word shows it; the upstream editor's own dark mode, which inverts the page, is
not used.
