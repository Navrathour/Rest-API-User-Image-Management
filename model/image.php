<?php
class Imageexception extends Exception{ }
class Image{
    private $_id;
    private $_title;
    private $_filename;
    private $_mimetype;
    private $_taskid;
    private $_uploadFolderLocation;

    public function __construct($id,$title,$filename,$mimetype,$taskid){
        $this->setID($id);
        $this->setTitle($title);
        $this->setFilename($filename);
        $this->setMimetype($mimetype);
        $this->setTaskid($taskid);
        $this->_uploadFolderLocation = "./../../taskimages/";

    }
   
    public function getID (){
        return $this->_id;
    }

    public function getTitle(){
        return $this->_title;
    }

    public function getFilename(){
        return $this->_filename;
    }

    public function getFileExtention(){
        $filenameparts = explode(".",$this->_filename);
        $lastArrayElement = count($filenameparts)-1;
        $FileExtention = $filenameparts[$lastArrayElement];
        return $FileExtention;
    }

    public function getMimetype(){
        return $this->_mimetype;
    }

    public function getTaskid(){
        return $this->_taskid;
    }
    
    public function getUploadFolderLocation(){
        return $this->_uploadFolderLocation;
    }

    public function getImageurl(){
        $httpOrhttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" ;
        $host = $_SERVER['HTTP_HOST'];
        $url = "v1/tasks/".$this->getTaskid()."/images/".$this->getID();
        return $httpOrhttps."//".$host.$url;
    }

    public function returnImageFile(){
        $filepath = $this->getUploadFolderLocation().$this->getTaskid().'/'.$this->getFilename();

        if(!file_exists($filepath)){
            throw new ImageException("Image File not found ");
        }

        header('Content-Type:'.$this->getMimetype());

        header('Content-Disposition: inline; filename="'.$this->getFilename().'"');

        if(!readfile($filepath)){
            http_response_code(404);
            exit;
        }
    }

    public function setID($id){
        if($id !== null && !is_numeric($id) || $id > 9223372036854775807 || $this->_id !== null ){
            throw new ImageException("Image ID Error");
        }
        $this->_id = $id ;
    }

    public function setTitle($title){
        if(strlen($title) < 1 || strlen($title) > 255){
            throw new ImageException("Image Title Error ");
        }
        $this->_title = $title;
    }

    public function setFilename($filename){
        if(strlen($filename) < 1 || strlen($filename) > 30 || preg_match("/^[a-zA-Z0-9_-]+(.jpg|.gif|.png)$/", $filename) != 1 ){
            throw new ImageException("Image Filename Error - must be between 1 and 30 characters and only .jpg.png.gif");
        }
        $this->_filename = $filename;
    }

    public function setMimetype($mimetype){
        if(strlen($mimetype) < 1 || strlen($mimetype) > 255){
            throw new ImageException("Image Mimetype Error ");
        }
        $this->_mimetype = $mimetype;
    }

    public function setTaskid($taskid){
        if($taskid !== null && !is_numeric($taskid) || $taskid > 9223372036854775807 || $this->_taskid !== null ){    
            throw new ImageException("Image Task ID Error");
        }
        $this->_taskid = $taskid ;
    }

    public function deleteImageFile(){
        $filepath = $this->getUploadFolderLocation().$this->getTaskid()."/".$this->getFilename();
        if(!file_exists($filepath)){
            if(!unlink($filepath)){
                throw new  ImageException("Failed to delete image file");
            }
        }
    }

    public function saveImageFile($tempFileName){
        $uploadedFilePath = $this->getUploadFolderLocation().$this->getTaskid().'/'.$this->getFilename(); 
        if(!is_dir($this->getUploadFolderLocation().$this->getTaskid())){
            if(!mkdir($this->getUploadFolderLocation().$this->getTaskid())){
              throw new Imageexception("failed to create image upload folder for task");
            }
        }   

        if(!file_exists($tempFileName)){
            throw new Imageexception("failed to upload image file");
        }

        if(!move_uploaded_file($tempFileName, $uploadedFilePath)){
            throw new Imageexception("failed to upload image file");
        }

    }

    public function renameImageFile($oldFilename,$newFilename){
        $originalFilepath = $this->getUploadFolderLocation().$this->getTaskid()."/".$oldFilename;
        $renamedFilepath = $this->getUploadFolderLocation().$this->getTaskid()."/".$newFilename;
        if(!file_exists($originalFilepath)){
            throw new ImageException("cannot find image file to rename");
        }
        if(!rename($originalFilepath,$renamedFilepath)){
            throw new ImageException("failed to update the filename");
        }
    }

    public function returnImageasArray(){
        $image = array();
        $image['id'] = $this->getID();
        $image['title'] = $this->getTitle();
        $image['filename'] = $this->getFilename();
        $image['mimetype'] = $this->getMimetype();
        $image['taskid'] = $this->getTaskid();
        $image['imageurl'] = $this->getImageurl();

        return  $image;

    }
}

?>