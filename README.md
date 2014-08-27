Multipart-File-Upload
=====================
** *Handles Multipart File Upload on server, handles both Sequential and Parallel chunk uploads* **

---
###@author :: Avneet Singh Bindra

###@version :: 1.0

### @params ::

* **targetDir** : *Relative server path at which file will be created, must have read write permissions for apache, web**

* **fileName** : *The unique file name used to identify the file, this should be different from the file name recieved from the sender(client) to avoid cases where multiple files of same name and size but different contents are being uploaded simultaneously*

* **originalName** : *This will be the frontend(client) selected file name*

* **filepath** : *Full file path using fileName (server computed variable)v

* **originalFilepath** : *Full filepath using originalName (server computed variable)*

* **chunk** : *The chunk number of currently uploaded chunk (chunks are considered to start from 0-xx where xx is last chunk, sent by client)*

* **chunks** : *Total number of chunks the file will be broken down into (i.e. if file will be broken down into 10 chunks the chunk numbers will be from 0-9). (sent by client)*

* **fileSize** : *File size of uploaded file in bytes (sent by client)*

* **fileType** : *Comupted MIME type for uploaded file (sent by client)*

* **uploadMethod** : *This will have either of two values 'P' or 'S' i.e. P = parallel uploads, S = Sequential Uploads*

---

###Licensed under MIT
