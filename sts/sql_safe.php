<?php
  // sql_safe.php - small helpers for parameterised queries in the legacy pages.
  // sts_q()  runs a prepared statement; all params are bound as strings.
  //          returns a mysqli_result for queries that produce rows, true for
  //          other successful statements, false on failure (see sts_err()).
  // sts_id() coerces a request value to a positive integer id (0 matches no row).
  // sts_ident() quotes an SQL identifier (DDL cannot be parameterised).

  if (!function_exists('sts_q'))
  {
    function sts_q($dbc, $sql, $params = array())
    {
      global $sts_last_err;
      $sts_last_err = '';
      $stmt = mysqli_prepare($dbc, $sql);
      if (!$stmt)
      {
        $sts_last_err = mysqli_error($dbc);
        return false;
      }
      if (count($params) > 0)
      {
        $vals = array();
        foreach ($params as $p)
        {
          $vals[] = is_scalar($p) ? (string) $p : '';
        }
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($vals)), ...$vals);
      }
      if (!mysqli_stmt_execute($stmt))
      {
        $sts_last_err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        return false;
      }
      $res = mysqli_stmt_get_result($stmt);
      mysqli_stmt_close($stmt);
      return ($res === false) ? true : $res;
    }

    function sts_err($dbc)
    {
      global $sts_last_err;
      return ($sts_last_err !== '' && $sts_last_err !== null) ? $sts_last_err : mysqli_error($dbc);
    }

    // positive integer id from a request value, or 0 (matches no row)
    function sts_id($v)
    {
      $v = is_scalar($v) ? trim((string) $v) : '';
      return (ctype_digit($v) && strlen($v) < 19) ? (int) $v : 0;
    }

    // quote an SQL identifier (backticks escaped)
    function sts_ident($name)
    {
      return '`' . str_replace('`', '``', $name) . '`';
    }

    // scalar request values (arrays become the default instead of a TypeError)
    function sts_get($key, $default = '')
    {
      return (isset($_GET[$key]) && is_scalar($_GET[$key])) ? (string) $_GET[$key] : $default;
    }

    function sts_post($key, $default = '')
    {
      return (isset($_POST[$key]) && is_scalar($_POST[$key])) ? (string) $_POST[$key] : $default;
    }

    function sts_html($v)
    {
      return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
  }
