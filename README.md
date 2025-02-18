# eZ Oracle

# What is the eZ Oracle extension?

This extension adds support for the Oracle database to eZ Publish by
plugging into the database framework. After it is installed you can
connect to any Oracle database that fits the minimum requirements.


# eZ Oracle version

The current version of eZ Oracle is [EXTENSION_VERSION].
You can find details about changes for this version in doc/changelogs/CHANGELOG-[EXTENSION_VERSION]


# License

This software is licensed under the [EXTENSION_LICENSE]. The complete
license agreement is included in the LICENSE file. For more information
or questions please contact info@ez.no


# Requirements

The following requirements exists for using eZ Oracle extension:

o  eZ Publish version:

   Make sure you eZ Publish version [EXTENSION_PUBLISH_VERSION] or higher, earlier
   versions will not work correctly with this extension.

o  Oracle version:

   Make sure you have Oracle 9, 10 or 11 (client and server).

   If the Oracle server version is less than 9.2, setting the environment
   variable NLS_LANG is mandatory to indicate that php expects to receive
   data from the database in the UTF8 character set.

o  PHP with Oracle support:

   Make sure your PHP installation has compiled in support for Oracle. To
   compile this you will need the Oracle client libraries and headers.
   The standard windows version of php includes the dll needed for oracle support
   (but the oracle client is still neded).

o  Oracle user with correct permissions:

   Make sure you have a user on the oracle server that can create the
   following elements: session, table, trigger, sequence and procedure.


# Installation

Please read the [INSTALL](INSTALL) file for installation instructions.

# Upgrading

Please read the online documentation at [Upgrading](https://doc.ezpublishlegacy.se7enx.com/Extensions/eZ-Publish-extensions/eZ-Publish-Extension-for-Oracle-R-database/5.0/Installation/Upgrading) for detailed upgrade instructions from previous versions.

# Clustering

Please read the online documentation at
[Clustering](https://doc.ezpublishlegacy.se7enx.com/Extensions/eZ-Publish-extensions/eZ-Publish-Extension-for-Oracle-R-database/2.0/Clustering.html) for detailed information on setting up an eZ Publish clustering configuration using an Oracle database.

# Troubleshooting

1. Read the FAQ
   ------------

   Some problems are more common than others. The most common ones are listed
   in the FAQ.

2. Support
   -------

   If you have find any problems not handled by this document or the FAQ you
   can contact eZ system trough the support system:
   https://se7enx.com/contact
