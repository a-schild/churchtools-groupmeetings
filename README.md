# churchtools-groupmeetings
Create ical feed from group meetings

Optionally it can also create a html page with the meetings.
In that case you have to modify the images.

Currently all meetings 1 year back and one year in the future are processed.
Currently there is no config to change this

## How to install
- git clone https://github.com/a-schild/churchtools-groupmeetings.git
- run composer install
- copy the src/config.sample to src/config.php and put in your values
- chown www-data:www-data * -R

- The application does store a cache in the src/cache subfolder.
  If the folder does not exist, it is created (Which means it must have write access in that location)
  
## Usage
- Just call https://yourserver/groupmeetings/index.php for the HTML page
- If you wish to get the ics feed/file, use index.php?format=ics instead
