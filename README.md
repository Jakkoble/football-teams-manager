# Soccerteams Challenge
This repo is a pimcore recruitment challenge and has no real world use case!
Demo: <a href="https://soccer.jakob.foo" target="_blank">https://soccer.jakob.foo</a>

## Installation
This installation process has been tested on a fresh Ubuntu 24.04.3 LTS installation. Linux environment is highly recommended!

### Requirements
- local git installation
- docker & docker compose installed

### Guide
1. Clone Repository: `git clone git@github.com:Jakkoble/football-teams-manager.git`
2. `cd football-teams-manager`
3. Set right permissions for environment: `sed -i "s|#user: '1000:1000'|user: '$(id -u):$(id -g)'|g" docker-compose.yaml`
4. Start docker containers: `sudo docker compose up -d`

⚠️ If you receive a warning that port 80 is already in use, you can choose a differnt one. For that run `sudo docker compose down` to stop all containers, open `docker-compose.yml` with you editor of choice and change the port for `nginx` from `"80:80"` to e. g. `"8080:80"`. Run `sudo docker compose up -d` again and the issue should be resolved. Note: From now on you application is only accessable under `http://localhost:8080` and the backend under `http://localhost:8080/admin`.

5. Install dependencies: `sudo docker compose exec php composer install`

This might result in an `pimcore.encryption.secret is not set` error, which is fine since this secret will be automatically set in the next steps.

6. Create needed directories: `sudo docker compose exec php mkdir -p var/tmp var/log var/cache`
7. Set permission for `var` directory: `sudo docker compose exec php chmod -R 777 var` (fine for local development environment)
8. Start setup process: `docker compose exec php vendor/bin/pimcore-install`
   1. Set admin user name
   2. Set admin user password
   3. Follow link for product key registration; make sure to select `Community Edition`
   4. Open Link in Mails and copy the product key 
   5. Paste Product key back in terminal
   6. Press 2x `Enter`
   7. Wait for setup completion
9. App is accessable under <a href="http://localhost" target="_blank">http://localhost</a> and admin panel under <a href="http://localhost/admin" target="_blank">http://localhost/admin</a>

## Fill with sample data
In the `resources/` directory you can find mock assets you can use to test the `.xlsx` data import. Hint: names for files & directories are case-sensitive!
1. Open admin panel and login:  <a href="http://localhost/admin" target="_blank">http://localhost/admin</a>
2. Add `data.xlsx` file: Go to `Assets` > RC on `Home` > `Add Asset(s)` > `Upload Files` > select `data.xlsx` at `<project-root>/resources/data.xlsx`
2. Create `Logos/` directory: Go to `Assets` > RC on `Home` > `Add Folder` > `Logos`
3. Upload logos: Go to `Assets` > RC on `Logos` directory > `Add Asset(s)` > `Upload Files` > select all 4 logos at `<project-root>/resources/Logos` (one image is intentionally missing to showcase the placeholder logo)
4. Run import script in terminal: `sudo docker compose exec php bin/console app:import-teams`
6. See the results at <a href="http://localhost" target="_blank">http://localhost</a>

## Excel Import CLI
This projects comes shipped with an import CLI for `.xlsx` files to create teams and players Data Objects with just one command. 

### Usage
The format and layout for the Excel sheet is very strict, therefore I suggest to add/remove rows to <a href="https://raw.githubusercontent.com/jakkoble/football-teams-manager/main/resources/data.xlsx" target="_blank">this file</a>, but do not change colum order!

#### File content
There are two sheets in the file: `teams` and `players`
and each sheet has some sample data added. If you want to add another team, simply create a new row with an ascending id (first colom). If you want to add players to this team, switch to the `players` sheet and also add new rows. Make sure to add the previously defined team id to the last column of each player. To use this file, upload it in the pimcore backend as an Assets.

#### Team logo
For the team logo you just need to upload it in the pimcore backend as an Asset in the `/Logos/` directory. (create if not exists) Then you can specify the name with file name extension in the `Logo` column inside the `teams` spreadsheet.

### Run CLI
```bash
sudo docker compose exec php bin/console app:import-teams
```

if you name you named your file different than `data.xlsx` you can specify the name like so:
```bash
sudo docker compose exec php bin/console app:import-teams myFile.xlsx
```
of cause you need to change `myFile.xlsx` to the actual filename. Its also possible to provide asubdirectory here. (e. g. `data/myFile.xlsx`)

