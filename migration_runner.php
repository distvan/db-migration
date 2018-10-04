<?php

/**
 * Class Migration
 * @author Istvan Dobrentei
 *
 * usage:
 * put all files into a directory "utility"
 * put mysql migration files into the SQL_FILES_FOLDER_NAME directory
 * file name conventions:
 * - start with sql + iso date format (YYYYMMDD)
 * - should end with .sql
 *
 * for example: sql20180912_anyname.sql
 * 
 * directory structure:
 *
 * utility/migration_runner.php
 * utility/sqlauto/sql00000000_init.sql
 * utility/sqlauto/*
 *
 * running example:
 * export HOST="127.0.0.1" USER="" PASS="" DB=""; php -f migration_runner.php 2>&1 | grep -v "[Warning]"
 * or
 * /usr/local/bin/php migration_runner.php --host=127.0.0.1 --database=bl_model_unit --user=root --password=345+4 --forceload=1 --droptables=1
 *
 * input paramteres:
 *
 *  --host
 *      the mysql database host
 *  --database
 *      the mysql database name
 *  --user
 *      the mysql database user
 *  --password
 *      the mysql database password
 *  --forceload
 *      it will load all sql files into the database, ignore the .migrated file content
 *  --droptables
 *      it will drop all tables inside the database
 *
 * every file import into the database only once and it creates a .migrated log file in the running directory
 *
 * In the SQL_FILES_FOLDER_NAME there are an init script (sql00000000_init.sql).
 * It is always the first script and it containts stored procedures.
 * They help to write IF NOT EXISTS like sql queries.
 *
 * Use queries like these to prevent mysql errors during script running:
 *
 *  - call AddColumnIfNotExists('tablename', 'fieldname1', 'VARCHAR(255) NOT NULL AFTER `fieldname2`');
 *    instead of:
 *    ALTER TABLE `tablename` ADD COLUMN `fieldname1` VARCHAR(255) NOT NULL AFTER `fieldname2`;
 *
 *  - call AddConstraintIfNotExists('tablename', 'constraintname', 'FOREIGN KEY (field) REFERENCES table(field)')
 *    instead of:
 *    ALTER TABLE `tablename` ADD CONSTRAINT `constraintname` FOREIGN KEY (field) REFERENCES table(field);
 *
 *  - call AddIndexIfNotExists('tablename', 'indexname', 'columns')
 *    instead of:
 *    ALTER TABLE `tablename` ADD KEY `indexname` (`column1`, `column2`, `column3`),
 *    or
 *    ALTER TABLE `tablename` ADD INDEX (`column`)
 *    or
 *    CREATE INDEX `indexname` ON `tablename` (`column`)
 */
class Migration
{
    const TAB = "\t";
    const SQL_FILES_FOLDER_NAME = "sqlauto";
    const LAST_RUNNING_FILES_NAME = ".migrated";
    const RUNNING_DIRECTORY = "utility";
    
    protected $_sqlPath;
    protected $_config;

    /**
     * Migration constructor.
     * @param $config
     */
    public function __construct($config)
    {
        if(!$this->validConfig($config))
        {
            exit(1);
        }
        $this->_config = $config;
        $this->_sqlPath = __DIR__ . DIRECTORY_SEPARATOR . self::SQL_FILES_FOLDER_NAME;
        if(isset($config['DROP_TABLES']) && $config['DROP_TABLES'])
        {
            $this->deleteAllTables();
        }
        $files = $this->getNotMigratedFiles();
        $this->createDatabase();
        $this->importIntoDatabase($files);
    }

    protected function validConfig($config)
    {
        $valid = true;

        if(!isset($config['HOST']) || empty($config['HOST']))
        {
            echo 'You need to setup database host!' . PHP_EOL;
            $valid = false;
        }

        if(!isset($config['USER']) || empty($config['USER']))
        {
            echo 'You need to setup database user!' . PHP_EOL;
            $valid = false;
        }

        if(!isset($config['DB']) || empty($config['DB']))
        {
            echo 'You need to setup database name!' . PHP_EOL;
            $valid = false;
        }

        return $valid;
    }

    /**
     * Return the name of the never runned sql file
     *
     * @return array
     */
    protected function getNotMigratedFiles()
    {
        $files = array();
        $except = array();

        if(is_file(self::LAST_RUNNING_FILES_NAME))
        {
            $file = new SplFileObject(self::LAST_RUNNING_FILES_NAME);
            while(!$file->eof())
            {
                $data = $file->fgetcsv(self::TAB);
                array_push($except, $data[0]);
            }
        }

        $dir = new DirectoryIterator($this->_sqlPath);

        foreach($dir as $file)
        {
            if($file->isFile() && $file->getExtension() == 'sql' &&
                (!in_array($file->getFilename(), $except) || $this->_config['FORCELOAD']))
            {
                array_push($files, $file->getFilename());
            }
        }

        return $this->sortFileNames($files);
    }

    /**
     * Sort names by date Ascendant order
     *
     * @param $items
     * @param string $format
     * @return array
     */
    protected function sortFileNames($items, $format="Ymd")
    {
        $list = array();
        $result = array();

        foreach($items as $item)
        {
            $parts = explode("_", $item);
            if(isset($parts[0]) && strlen($parts[0]) > 8)
            {
                $dateStr = substr($parts[0], 3);
                $d = DateTime::createFromFormat($format, $dateStr);
                if($d && $d->format($format) === $dateStr)
                {
                    array_push($list, array(
                        'name' => $item,
                        'date' => $d
                    ));
                }
                elseif($dateStr == '00000000')
                {
                    array_push($result, $item);
                }
            }
        }

        usort($list, function($item1, $item2){
            if($item1['date'] == $item2['date']){
                return 0;
            }
            return $item1['date'] < $item2['date'] ? -1 : 1;
        });

        foreach($list as $item){
            array_push($result, $item['name']);
        }

        return $result;
    }

    /*
     * Create database if not exists
     *
     * */
    protected function createDatabase()
    {
        $command = 'mysql'
            . ' --host=' . $this->_config['HOST']
            . ' --user=' . $this->_config['USER']
            . ' --password=' . $this->_config['PASS']
            . ' -e "CREATE DATABASE IF NOT EXISTS ' . $this->_config['DB'] . '"';

        exec($command, $output, $code);

        if($code)
        {
            echo $command.PHP_EOL;
            echo 'Error Create DB' . PHP_EOL;
            exit(1);
        }
    }
    
    /**
     * Import sql files into database and create a log
     *
     * @param $files
     */
    protected function importIntoDatabase($files)
    {
        $command = 'mysql'
            . ' --host=' . $this->_config['HOST']
            . ' --user=' . $this->_config['USER']
            . ' --password=' . $this->_config['PASS']
            . ' --database=' . $this->_config['DB']
            . ' < ';

        $now = new DateTime();

        $fileObj = new SplFileObject(self::LAST_RUNNING_FILES_NAME, 'a');

        foreach($files as $file)
        {
            exec($command . self::SQL_FILES_FOLDER_NAME . DIRECTORY_SEPARATOR . $file, $output, $code);
            if(empty($code))
            {
                $fileObj->fputcsv(array($file, $now->format('Y-m-d H:i:s')), self::TAB);
            }
            else
            {
                echo 'Error' . PHP_EOL;
                exit(1);
            }
        }
    }

    /**
     * Delete all tables in the database
     *
     */
    protected function deleteAllTables()
    {
        $command = 'mysql'
            . ' --host=' . $this->_config['HOST']
            . ' --user=' . $this->_config['USER']
            . ' --password=' . $this->_config['PASS']
            . ' -Nse "SHOW TABLES" ' . $this->_config['DB']
            . ' | while read table;'
            . 'do mysql'
            . ' --host=' . $this->_config['HOST']
            . ' --user=' . $this->_config['USER']
            . ' --password=' . $this->_config['PASS']
            . ' -e "SET FOREIGN_KEY_CHECKS=0;DROP TABLE $table" ' . $this->_config['DB']
            . ';done';

        exec($command, $output, $code);

        if($code)
        {
            echo 'Error' . PHP_EOL;
            exit(1);
        }
    }
    
    public static function isInRightDirectory()
    {
        $dir  = getcwd();
        $parts = explode(DIRECTORY_SEPARATOR, $dir);

        return $parts[count($parts)-1] == Migration::RUNNING_DIRECTORY;
    }
}

$options = getopt("", array("host::", "user::", "password::", "database::", "forceload::", "droptables::", "help::"));

#######################
# CHECKING HELP PARAM #
#######################
if(isset($options['help'])){
    echo "Usage: " . PHP_EOL . "migration_runner.php --host=<yourhost> --user=<dbuser> --password=<dbpassword> --database=<dbname> --forceload=<1 or 0> --droptables=<1 or 0>" . PHP_EOL.PHP_EOL;
    exit(1);
}
###############################
# CHECKING RUNNING DIRECTORY  #
###############################
if(!Migration::isInRightDirectory()){
    echo "Please run the script from the " . Migration::RUNNING_DIRECTORY . " directory!" . PHP_EOL;
    exit(1);
}
####################
# SET INPUT PARAMS #
####################

$config['HOST'] = isset($options['host']) ? $options['host'] : '';
$config['HOST'] = empty($config['HOST']) && defined('MYSQL_HOST') ? MYSQL_HOST : $config['HOST'];
$config['HOST'] = empty($config['HOST']) && getenv('HOST') ? getenv('HOST') : $config['HOST'];

$config['DB'] = isset($options['database']) ? $options['database'] : '';
$config['DB'] = empty($config['DB']) && defined('MYSQL_DATABASE') ? MYSQL_DATABASE : $config['DB'];
$config['DB'] = empty($config['DB']) && getenv('DB') ? getenv('DB') : $config['DB'];

$config['USER'] = isset($options['user']) ? $options['user'] : '';
$config['USER'] = empty($config['USER']) && defined('MYSQL_USER') ? MYSQL_USER : $config['USER'];
$config['USER'] = empty($config['USER']) && getenv('USER') ? getenv('USER') : $config['USER'];

$config['PASS'] = isset($options['password']) ? $options['password'] : '';
$config['PASS'] = empty($config['PASS']) && defined('MYSQL_PASSWORD') ? MYSQL_PASSWORD : $config['PASS'];
$config['PASS'] = empty($config['PASS']) && getenv('PASS') ? getenv('PASS') : $config['PASS'];

$config['FORCELOAD'] = isset($options['forceload']) ? $options['forceload'] : '';
$config['FORCELOAD'] = empty($config['FORCELOAD']) && defined('FORCELOAD') ? FORCELOAD : $config['FORCELOAD'];
$config['FORCELOAD'] = empty($config['FORCELOAD']) && getenv('FORCELOAD') ? getenv('FORCELOAD') : $config['FORCELOAD'];

$config['DROP_TABLES'] = isset($options['droptables']) ? $options['droptables'] : '';
$config['DROP_TABLES'] = empty($config['DROP_TABLES']) && defined('DROP_TABLES') ? DROP_TABLES : $config['DROP_TABLES'];
$config['DROP_TABLES'] = empty($config['DROP_TABLES']) && getenv('DROP_TABLES') ? getenv('DROP_TABLES') : $config['DROP_TABLES'];

$migration = new Migration($config);
