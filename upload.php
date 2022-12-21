<?php
//file type   $_FILES["file"]["type"]
//file size   $_FILES["file"]["size"]
//file error   $_FILES["file"]["error"]
//file name   $_FILES["file"]["name"]
//file tmp name $_FILES["files"]["tmp_name"]
    if ((($_POST["filetype"] == 0) ||
        ($_POST["filetype"] == 1) ||
        ($_POST["filetype"] == 9) )
        &&($_POST["gwpath"] != "")
        &&($_POST["sn"] != "")
        && ($_FILES["file"]["size"] < 1024*1024*50))
    {
        if ($_FILES["file"]["error"] > 0)
        {
            echo "error";
        }
        else
        {
            $dirname ="down/".$_POST["sn"];
            if(!is_dir($dirname))
                mkdir($dirname, true);
            $filename = iconv("GBK", "UTF-8",$dirname."/". $_FILES["file"]["name"]);
            if (file_exists($filename))
            {
                unlink($filename);
            }

            {
                move_uploaded_file($_FILES["file"]["tmp_name"], $filename);
                $mysql_server_name="127.0.0.1"; //数据库服务器名称
                $mysql_username="root"; // 连接数据库用户名
                $mysql_password="pilot@123456"; // 连接数据库密码
                $mysql_database="gateway"; // 数据库的名字

                // 连接到数据库
                $mysqli=new mysqli($mysql_server_name, $mysql_username, $mysql_password,  $mysql_database);
                echo $mysqli->error;
                // 从表中提取信息的sql语句
                if($_POST["sn"] == "0")
                    $strsql="replace into downfile(gwsn, filename, filemd5, filetype, gwpath, status) select sn".
                        ",'/".$filename."','".md5_file($filename)."','".$_POST["filetype"]."','".$_POST["gwpath"]."','0' from gateway";
                else
                    $strsql="replace into downfile(gwsn, filename, filemd5, filetype, gwpath, status) value('".$_POST["sn"].
                        "','/".$filename."','".md5_file($filename)."','".$_POST["filetype"]."','".$_POST["gwpath"]."','0')";

                $mysqli->query($strsql);
                echo $mysqli->error;
                // 关闭连接
                $mysqli->close();
            }
            echo "ok";
        }
    }
    else
    {
        echo "error";
    }
?>
