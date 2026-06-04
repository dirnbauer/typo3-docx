..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Open a document
===============

#. Sign in to the TYPO3 backend.
#. Open :guilabel:`Media`.
#. Browse to a folder that contains a `.docx` file.
#. Click the primary action :guilabel:`Edit DOCX`.

The editor opens in the Media submodule. Use the editor toolbar to save; the
file in FAL is updated in place.

Permissions
===========

Users need read permission to open a file and write permission on the storage
to save. Without write permission the editor opens in viewing mode.

Collaboration
=============

When several users edit the same file:

- The toolbar shows how many editors are currently online.
- Saving increments a server-side revision counter.
- If someone else saved while you were editing, the next save is rejected and
  a reload banner appears.

Reload to load the newest FAL contents before continuing.
