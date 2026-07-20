<?php
function backup_tables($dbc, $backup_name)
{
  //get all of the tables
  $tables = array();
  $sql = 'show tables';
  $result = mysqli_query($dbc, $sql);
  while($row = mysqli_fetch_row($result))
  {
    $tables[] = $row[0];
  }

  //loop through the tables
  $return = '';
  foreach($tables as $table)
  {
    // get the contents of each table
    $sql = 'select * from `' . $table . '`';
    $result = mysqli_query($dbc, $sql);
    $numColumns = mysqli_field_count($dbc);

    // drop any existing table before trying to restore it
    // separate all sql statements with comment lines (#) so that the statements can be parsed when restoring the database
    // IF EXISTS + restore_sql_helpers case-insensitive drop cover lower_case_table_names=2
    $return .= 'drop table if exists `' . $table . '`;' . PHP_EOL . '#' . PHP_EOL;

    // build the create table statement
    $sql = 'show create table `' . $table . '`';
    $result2 = mysqli_query($dbc, $sql);
    $row2 = mysqli_fetch_row($result2);

    $return .= $row2[1] . ";" . PHP_EOL . '#' . PHP_EOL;

    for($i = 0; $i < $numColumns; $i++)
    {
      while($row = mysqli_fetch_row($result))
      {
        $return .= 'insert into `' . $table . '` values(';
        for($j=0; $j < $numColumns; $j++)
        {
          $row[$j] = addslashes($row[$j]);
          if (isset($row[$j]))
          {
            $return .= '"' . $row[$j] . '"' ;
          }
          else
          {
            $return .= '""';
          }
          if ($j < ($numColumns-1))
          {
            $return.= ',';
          }
        }
        $return .= ");" . PHP_EOL . '#' . PHP_EOL;
      }
    }

    $return .= PHP_EOL . '#' . PHP_EOL;
  }
  //save the file to the backups directory
  $handle = fopen('./backups/' . $backup_name,'w+');
  fwrite($handle,$return);
  fclose($handle);
}
?>