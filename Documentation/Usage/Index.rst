..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Open and edit
=============

#.  Open :guilabel:`File` > :guilabel:`Filelist` and browse to a `.docx` file.
#.  Choose the :guilabel:`Edit DOCX` action.
#.  Edit in the full-page editor. The style dropdown offers
    :guilabel:`Normal` and :guilabel:`Heading 1`–:guilabel:`Heading 4`; the same
    headings are available as H1–H4 buttons at the start of the toolbar.

Save
====

-   :guilabel:`Save` in the docheader or :kbd:`Ctrl+S` / :kbd:`Cmd+S` writes the
    document back to the same FAL file.
-   :guilabel:`Save as` opens the folder browser; after picking a folder you
    are asked for a file name (``.docx`` is appended) and the editor reopens the
    new file. An existing name gets a ``_01`` suffix.
-   :guilabel:`Download` fetches the stored file.
-   Feedback arrives as backend notifications ("Saved to fileadmin / …").

Permissions
===========

Read permission on storage and file opens the document in viewing mode; write
permission enables saving. The :guilabel:`Edit DOCX` action is only shown for
readable files.

Working together
================

A badge above the editor shows how many editors currently have the file open.
Every save increments a revision counter; if someone else saved while you were
editing, your save is rejected and a warning banner offers
:guilabel:`Reload document`.
