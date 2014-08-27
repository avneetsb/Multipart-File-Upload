<?php

// Library for handling multipart file upload
// Supports both Parallel | Sequential uploads for multipart data

/*
*	@author     Avneet Singh Bindra
*	@version    1.0
*	@params ::
*	-- targetDir : Relative server path at which file will be created, must have read write permissions for apache, web
*	-- fileName : The unique file name used to identify the file, this should be different from the file name recieved from
*				the sender(client) to avoid cases where multiple files of same name and size but different contents
*				are being uploaded simultaneously
*	-- originalName : This will be the frontend(client) selected file name
*	-- filepath : Full file path using fileName (server computed variable)
*	-- originalFilepath : Full filepath using originalName (server computed variable)
*	-- chunk : The chunk number of currently uploaded chunk (chunks are considered to start from 0-xx where xx is last chunk)
*				(sent by client)
*	-- totalChunk : Total number of chunks the file will be broken down into (i.e. if file will be broken down into 10 chunks
*				the chunk numbers will be from 0-9). (sent by client)
*	-- fileSize : File size of uploaded file in bytes (sent by client)
*	-- fileType : Comupted MIME type for uploaded file (sent by client)
*	-- uploadMethod : This will have either of two values 'P' or 'S'
*				i.e. P = parallel uploads, S = Sequential Uploads
*/	

class FileUploadHandler {
	
	private $targetDir;
	private $fileName;
	private $originalName;
	private $filepath;
	private $originalFilepath;
	private $chunk;
	private $chunks;
	private $fileSize;
	private $fileType;
	private $uploadMethod;

	public function __construct($uploadMethod, $targetDir, $fileName, $originalName, $curChunk, $totChunks,  $fileSize, $fileType, $cleanupTime = 172800, $apiResponseHandlerObj=NULL){

		$this->apiResponseHandlerObj = $apiResponseHandlerObj;
		$this->set_response_headers();
		$this->setTargetDir($targetDir);
		$this->setFilename($fileName);
		$this->setFilesize($fileSize);
		$this->setFiletype($fileType);
		$this->setOriginalName($originalName);
		$this->setUploadMethod($uploadMethod);
		$this->setFilepath();
		$this->setOriginalFilepath();
		$this->cleanupTargetDir($cleanupTime);
		$this->setCurrentlyUploadedChunk($curChunk);
		$this->setTotalChunks($totChunks);
		$this->writeChunksToFile();

	}

	public function set_response_headers(){

		if(!isset($this->apiResponseHandlerObj)){
			// Set headers to disallow caching and enable CORS
			// Make sure file is not cached (as it happens for example on iOS devices)
			header("Accept: */*");
			header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);
			header("Pragma: no-cache");
			header("Access-Control-Allow-Origin: *");
			header("Content-Type: application/json");
		}	

	}

	public function setTargetDir($targetDir){
		
		// Resolve target directory, default to uplaods/iosupload.
		if(isset($targetDir)){
			$this->targetDir = realpath(getenv("DOCUMENT_ROOT")) . $targetDir;
		} else {
			$this->targetDir = realpath(getenv("DOCUMENT_ROOT")) . '/uploads/appUploads';
		}

		// Create target directory if it doesnt exist
		if (!file_exists($this->targetDir)) {
			@mkdir($this->targetDir);
		}	
	}

	public function setFilename($fileName){

		// Get a file name
		if (isset($fileName) && $fileName!='' && $fileName!=NULL) {
			$this->fileName = $fileName;
		} else {
			$output = array('error'=>'uniqueFilename not set or empty');
			$this->sendResponse($output, 'FAILURE');
		}

	}

	public function setFilesize($fileSize){

		// Get a file size
		if (isset($fileSize) && $fileSize!='' && $fileSize!=NULL) {
			$this->fileSize = $fileSize;
		} else {
			$output = array('error'=>'fileSize not set or empty');
			$this->sendResponse($output, 'FAILURE');
		}

	}

	public function setFiletype($fileType){

		// Get a file type
		if (isset($fileType) && $fileType!='' && $fileType!=NULL) {
			$this->fileType = $fileType;
		} else {
			$output = array('error'=>'fileType not set or empty');
			$this->sendResponse($output, 'FAILURE');
		}

	}

	public function setOriginalName($originalName){
		// Get a original file name
		if (isset($originalName) && $originalName!='' && $originalName!=NULL) {
			$this->originalName = $originalName;
		} else {
			$output = array('error'=>'originalFilename not set or empty');
			$this->sendResponse($output, 'FAILURE');
		}
	}

	public function setUploadMethod($uploadMethod){
		// Get uploadMethod
		// P = Parallel uploads
		// S = Serial Uploads
		if (isset($uploadMethod) && $uploadMethod!='' && $uploadMethod!=NULL && (strtoupper($uploadMethod) == 'P' || strtoupper($uploadMethod) == 'S')) {
			$this->uploadMethod = $uploadMethod;
		} else {
			$output = array('error'=>'uploadMethod not set or empty');
			$this->sendResponse($output, 'FAILURE');
		}
	}

	public function setFilepath(){
		$this->filepath = $this->targetDir . DIRECTORY_SEPARATOR . $this->fileName;
	}

	public function setOriginalFilepath(){
		$this->originalFilepath = $this->targetDir . DIRECTORY_SEPARATOR . $this->originalName;	
	}

	// This function will delete old files for which upload was started but couldn't be completed within the last 2 days(default)
	public function cleanupTargetDir($maxFileAge){

		if (!is_dir($this->targetDir) || !$dir = opendir($this->targetDir)) {
			$output = array('error'=>'Failed to open temp directory, '.$this->targetDir);
			$this->sendResponse($output, 'FAILURE');
		}

		while (($file = readdir($dir)) !== false) {
			$tmpFilepath = $this->targetDir . DIRECTORY_SEPARATOR . $file;

			// If temp file is current file proceed to the next
			// This is the case for sequential upload where only one .part file is created
			if ($tmpFilepath == "{$this->filepath}.part") {
				continue;
			}

			// Remove temp file if it is older than the max age and is not the current file
			if (preg_match('/\.part$/', $file) || preg_match('/\.part\d*$/', $file) && (filemtime($tmpFilepath) < time() - $maxFileAge)) {
				@unlink($tmpFilepath);
			}
		}

		closedir($dir);
	}

	public function setCurrentlyUploadedChunk($curChunk){
		if(is_numeric($curChunk)){
			// Take current chunk value, or assume this is the 0(first chunk)
			$this->chunk = isset($curChunk) ? intval($curChunk) : 0;
		} else {
			$output = array('error'=>'Chunk number should have a numeric value');
			$this->sendResponse($output, 'FAILURE');
		}
	}

	public function setTotalChunks($totChunks){
		if(is_numeric($totChunks)){
			$this->chunks = isset($totChunks) ? intval($totChunks) : 0;
		} else {
			$output = array('error'=>'Total number of chunks should have a numeric value');
			$this->sendResponse($output, 'FAILURE');
		}
	}

	public function writeChunksToFile(){
		
		if(strtoupper($this->uploadMethod) == 'S'){
			// Code to handle sequential uploads
			// Open temp file
			if (!$out = @fopen("{$this->filepath}.part", $this->chunks ? "ab" : "wb")) {
				$output = array('error'=>'Failed to open output stream');
				$this->sendResponse($output, 'FAILURE');
			}

			if (!empty($_FILES)) {
				if ($_FILES["file"]["error"] || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
					$output = array('error'=>'Failed to move uploaded file');
					$this->sendResponse($output, 'FAILURE');
				}

				// Read binary input stream and append it to temp file
				if (!$in = @fopen($_FILES["file"]["tmp_name"], "rb")) {
					$output = array('error'=>'Failed to open input stream');
					$this->sendResponse($output, 'FAILURE');
				}
			} else {	
				if (!$in = @fopen("php://input", "rb")) {
					$output = array('error'=>'Failed to open input stream');
					$this->sendResponse($output, 'FAILURE');
				}
			}

			while ($buff = fread($in, 4096)) {
				fwrite($out, $buff);
			}

			@fclose($out);
			@fclose($in);

			// Check if file has been uploaded
			if (!$this->chunks || $this->chunk == $this->chunks - 1) {
				// Strip the temp .part suffix off 
				rename("{$this->filepath}.part", $this->filepath);
				// Rename the file to the original file
				if(!file_exists($this->originalFilepath)){
					rename($this->filepath, $this->originalFilepath);
					// verifyFile recieved with params sent
					$uploadSuccess = $this->verifyFileIntegrity($this->originalFilepath, $this->fileSize, $this->fileType);
				} else {
					$filepath = pathinfo($this->originalFilepath);
					$dirname = $filepath['dirname'];
					$filename = $filepath['basename'];
					$filebroken = explode( '.', $filename);
					array_pop($filebroken);
					$basename = implode('.', $filebroken);
					$extension = $filepath['extension'];
					$newName = $dirname."/".uniqid($basename."_").".".$extension;
					rename($this->filepath, $newName);
					// verifyFile recieved with params sent
					$uploadSuccess = $this->verifyFileIntegrity($newName, $this->fileSize, $this->fileType);
				}
			}
		}

		if(strtoupper($this->uploadMethod) == 'P'){
			// Code to handle parallel uploads
			// Open temp file
			if (!$out = @fopen("{$this->filepath}.part{$this->chunk}", $this->chunks ? "ab" : "wb")) {
				$output = array('error'=>'Failed to open output stream');
				$this->sendResponse($output, 'FAILURE');
			}

			if (!empty($_FILES)) {
				if ($_FILES["file"]["error"] || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
					$output = array('error'=>'Failed to move uploaded file');
					$this->sendResponse($output, 'FAILURE');
				}

				// Read binary input stream and append it to temp file
				if (!$in = @fopen($_FILES["file"]["tmp_name"], "rb")) {
					$output = array('error'=>'Failed to open input stream');
					$this->sendResponse($output, 'FAILURE');
				}
			} else {	
				if (!$in = @fopen("php://input", "rb")) {
					$output = array('error'=>'Failed to open input stream');
					$this->sendResponse($output, 'FAILURE');
				}
			}

			while ($buff = fread($in, 4096)) {
				fwrite($out, $buff);
			}

			@fclose($out);
			@fclose($in);

			// Check if file has been uploaded
			// Here make sure the last chunk number is uploaded at the end for parallel uploads
			if (!$this->chunks || $this->chunk == $this->chunks - 1) {
				// Find and append all part files in the correct order
				for($i=0;$i<$this->chunks;$i++){
					$tempFile = "{$this->filepath}.part{$i}";
					if(!file_exists($tempFile)){
						$output = array('error'=>'Last chunk was uploaded before all previous chunks were uploaded');
						$this->sendResponse($output, 'FAILURE');
					}
					$out = @fopen("{$this->filepath}.part", "ab");
					$in = @fopen($tempFile, "rb");
					while ($buff = fread($in, 4096)) {
						fwrite($out, $buff);
					}
					@fclose($out);
					@fclose($in);
					// Remove part files
					@unlink($tempFile);
				}
				// Strip the temp .part suffix off 
				rename("{$this->filepath}.part", $this->filepath);
				// Rename the file to the original file
				if(!file_exists($this->originalFilepath)){
					rename($this->filepath, $this->originalFilepath);
					// verifyFile recieved with params sent
					$uploadSuccess = $this->verifyFileIntegrity($this->originalFilepath, $this->fileSize, $this->fileType);
				} else {
					$filepath = pathinfo($this->originalFilepath);
					$dirname = $filepath['dirname'];
					$filename = $filepath['basename'];
					$filebroken = explode( '.', $filename);
					array_pop($filebroken);
					$basename = implode('.', $filebroken);
					$extension = $filepath['extension'];
					$newName = $dirname."/".uniqid($basename."_").".".$extension;
					rename($this->filepath, $newName);
					// verifyFile recieved with params sent
					$uploadSuccess = $this->verifyFileIntegrity($newName, $this->fileSize, $this->fileType);
				}
			}
		}
		
		// Response based on above computations
		if(isset($uploadSuccess) && $uploadSuccess == 1 && isset($newName)){
			$output = array('success'=>'All chunks uploaded successfully', 'fileUploadedAt'=>$newName, 'chunkNumber' => $this->chunk);
			$this->sendResponse($output, 'SUCCESS');
		} elseif(isset($uploadSuccess) && $uploadSuccess == 0 && isset($newName)){
			// delete uploaded file
			@unlink($newName);
			$output = array('error'=>"File integrity failed, please re-upload the file again");
			$this->sendResponse($output, 'FAILURE');
		} elseif(isset($uploadSuccess) && $uploadSuccess == 1){
			$output = array('success'=>"All chunks uploaded successfully", 'fileUploadedAt'=>$this->originalFilepath, 'chunkNumber' => $this->chunk);
			$this->sendResponse($output, 'SUCCESS');
		} elseif(isset($uploadSuccess) && $uploadSuccess == 0) {
			// delete uploaded file
			@unlink($this->originalFilepath);
			$output = array('error'=>"File integrity failed, please re-upload the file again");
			$this->sendResponse($output, 'FAILURE');
		} else {
			$output = array('success'=>"Chunk $this->chunk uploaded successfully", 'chunkNumber' => $this->chunk);
			$this->sendResponse($output, 'SUCCESS');
		}
	}

	public function verifyFileIntegrity($originalFilepath, $fileSize, $fileType){

		// This function compared the new multipart file params as created on server with client params
		$recieved_fileSize = filesize($originalFilepath);
		$recieved_fileType = mime_content_type($originalFilepath);
		if($fileSize == $recieved_fileSize && $fileType == $recieved_fileType){
			return 1;
		} else {
			return 0;
		}
	}

	public function sendResponse($output, $status=NULL){

		// Our basic response handler
		if(isset($this->apiResponseHandlerObj)){
			if(isset($status)){
				switch ($status) {
					case 'SUCCESS':
						$this->apiResponseHandlerObj->setHttpArray(ResponseHandlerConfig::$SUCCESS);
						break;
					case 'FAILURE':
						$this->apiResponseHandlerObj->setHttpArray(ResponseHandlerConfig::$FAILURE);
						break;
					default:
						# code...
						break;
				}
			}
			$this->apiResponseHandlerObj->setResponseBody($output);
			$this->apiResponseHandlerObj->generateResponse();
			die();
		} else {
			if($status == 'FAILURE'){
				// Standard 500 Server error
				header("HTTP/1.0 500 Internal Server Error");
			}
			// Append a response body with brief description of error message
			die(json_encode($output));
		}
	}
}

