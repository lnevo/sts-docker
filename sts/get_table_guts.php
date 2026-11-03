<?php
  // this routine returns the result of a database query in a simple <tr><td></td></tr> format to the calling HttpRequest

  // get a database connection
  require 'open_db.php';
  $dbc = open_db();

  // get the incoming parameter
  $sql = urldecode(isset($_REQUEST['sql_query']) && is_scalar($_REQUEST['sql_query']) ? $_REQUEST['sql_query'] : '');

  // this endpoint only ever displays data: accept a single read-only select
  if (!preg_match('/^\s*select\b/i', $sql) || strpos($sql, ';') !== false)
  {
    print '<tr><td>SQL Error</td></tr><tr><td>Only a single SELECT query is allowed</td></tr>';
    exit;
  }

  // run the query
  if (!$rs = mysqli_query($dbc, $sql))
  {
    print '<tr><td>SQL Error</td></tr><tr><td>' . htmlspecialchars(mysqli_error($dbc), ENT_QUOTES, 'UTF-8') . '</td></tr>';
  }
  else
  {
    $table_guts = "";
    $num_cols = mysqli_num_fields($rs);
    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_row($rs))
      {
        $table_guts .= '<tr>';
        for ($i=0; $i<$num_cols; $i++)
        {
          $table_guts .= '<td>' . htmlspecialchars((string) $row[$i], ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $table_guts .= '</tr>';
      }
      print $table_guts;
    }
    else
    {
      print '<tr><td>Zero rows returned by query</td></tr>';
    }
  }
?>  
