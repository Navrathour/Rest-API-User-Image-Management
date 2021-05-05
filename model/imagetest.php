<?php

require_once('image.php');

try{
    $image = new Image(1,"Image Title here","image1.jpg","image/jpeg",1);
    header('Content-type:application/json;charset=UTF-8');
    echo json_encode($image->returnImageasArray());
}
catch(ImageException $ex){
    echo "error:".$ex->getMessage();
}


?>