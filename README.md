# db-migration
Migration Class to help automatization


The migration Class is a command line php utility and it helps to automatizate database changings and its migrations.
It is useful for example to create a Continous Integration environment.
The sqlauto directory containts an init script and some mysql stored procedures.

Usage:  

put mysql migration files into the sqlauto directory  
file name conventions:  
    - start with sql + iso date format (YYYYMMDD)  
    - should end with .sql  

Run:  

/usr/local/bin/php migration_runner.php --host=127.0.0.1 --database=bl_model_unit --user=root --password=345+4 --forceload=1 --droptables=1

input parameters:

   --host  
       the mysql database host  
   --database  
       the mysql database name  
   --user  
       the mysql database user  
   --password  
       the mysql database password  
   --forceload  
       it will load all sql files into the database, ignore the .migrated file content  
   --droptables  
       it will drop all tables inside the database  
