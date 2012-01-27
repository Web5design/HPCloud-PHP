<?php

/**
 * @file
 * Contains the stream wrapper for `swift://` URLs.
 */

namespace HPCloud\Storage\ObjectStorage;

use \HPCloud\Bootstrap;
use \HPCloud\Storage\ObjectStorage;

/**
 * Provides stream wrapping for Swift.
 *
 * This provides a full stream wrapper to expose `swift://` URLs to the
 * PHP stream system.
 *
 * Swift streams provide authenticated and priviledged access to the
 * swift data store. These URLs are not generally used for granting
 * unauthenticated access to files (which can be done using the HTTP
 * stream wrapper -- no need for swift-specific logic).
 *
 * URL Structure
 *
 * This takes URLs of the following form:
 *
 * @code
 * swift://CONTAINER/FILE
 * @endcode
 *
 * Example:
 *
 * @code
 * swift://public/example.txt
 * @encode
 *
 * The example above would access the `public` container and attempt to
 * retrieve the file named `example.txt`.
 *
 * Slashes are legal in Swift filenames, so a pathlike URL can be constructed
 * like this:
 *
 * @code
 * swift://public/path/like/file/name.txt
 * @endcode
 *
 * The above would attempt to find a file in object storage named
 * `path/like/file/name.txt`.
 *
 * A note on UTF-8 and URLs: PHP does not yet natively support many UTF-8
 * charcters in URLs. Thus, you ought to urlencode() your container name
 * and object name (path) if there is any possibility that it will contain
 * UTF-8 characters.
 *
 * Locking
 *
 * This library does not support locking (e.g. flock()). This is because the
 * OpenStack Object Storage implementation does not support locking. But there
 * are a few related things you should keep in mind:
 *
 * - Working with a stream is essentially working with a COPY OF a remote file.
 *   Local locking is not an issue.
 * - If you open two streams for the same object, you will be working with
 *   TWO COPIES of the object. This can, of course, lead to nasty race
 *   conditions if each copy is modified.
 *
 * Usage
 *
 * The principle purpose of this wrapper is to make it easy to access and
 * manipulate objects on a remote object storage instance. Managing
 * containers is a secondary concern (and can often better be managed using
 * the HPCloud API). Consequently, almost all actions done through the
 * stream wrapper are focused on objects, not containers, servers, etc.
 *
 * Retrieving an Existing Object
 *
 * Retrieving an object is done by opening a file handle to that object.
 *
 * Writing an Object
 *
 * Nothing is written to the remote storage until the file is closed. This
 * keeps network traffic at a minimum, and respects the more-or-less stateless
 * nature of ObjectStorage.
 *
 * USING FILE/STREAM RESOURCES
 *
 * In general, you should access files like this:
 *
 * @code
 * <?php
 * // Set up the context.
 * $context = stream_context_create(
 *   array('swift' => array(
 *     'account' => ACCOUNT_NUMBER,
 *     'key' => SECRET_KEY,
 *     'tenantId' => TENANT_ID
 *     'endpoint' => AUTH_ENDPOINT_URL,
 *   )
 *  )
 * );
 * // Open the file.
 * $handle = fopen('swift://mycontainer/myobject.txt', 'r+', FALSE, $context);
 *
 * // You can get the entire file, or use fread() to loop through the file.
 * $contents = stream_get_contents($handle);
 *
 * fclose($handle);
 * ?>
 * @endcode
 *
 * Notes:
 *
 * - file_get_contents() works fine.
 * - You can write to a stream, too. Nothing is pushed to the server until
 *   fflush() or fclose() is called.
 * - Mode strings (w, r, w+, r+, c, c+, a, a+, x, x+) all work, though certain
 *   constraints are slightly relaxed to accomodate efficient local buffering.
 * - Files are buffered locally.
 *
 * USING FILE-LEVEL FUNCTIONS
 *
 * PHP provides a number of file-level functions that stream wrappers can
 * optionally support. Here are a few such functions:
 *
 * - file_exists()
 * - is_readable()
 * - stat()
 * - filesize()
 * - fileperms()
 *
 * The HPCloud stream wrapper provides support for these file-level functions.
 * But there are a few things you should know:
 *
 * - Each call to one of these functions generates at least one request. It may
 *   be as many as three:
 *   * An auth request
 *   * A request for the container (to get container permissions)
 *   * A request for the object
 * - IMPORTANT: Unlike the fopen()/fclose()... functions NONE of these functions
 *   retrieves the body of the file. If you are working with large files, using
 *   these functions may be orders of magnitude faster than using fopen(), etc.
 *   (The crucial detail: These kick off a HEAD request, will fopen() does a
 *   GET request).
 * - You must use Bootstrap::setConfiguration() to pass in all of the values you
 *   would normally pass into a stream context:
 *   * endpoint
 *   * account
 *   * key
 * - Most of the information from this family of calls can also be obtained using
 *   fstat(). If you were going to open a stream anyway, you might as well use
 *   fopen()/fstat().
 * - stat() and fstat() fake the permissions and ownership as follows:
 *   * uid/gid are always sset to the current user. This basically assumes that if
 *     the current user can access the object, the current user has ownership over
 *     the file. As the OpenStack ACL system developers, this may change.
 *   * Mode is faked. Swift uses ACLs, not UNIX mode strings. So we fake the string:
 *     - 770: The ACL has the object marked as private.
 *     - 775: The ACL has the object marked as public.
 *     - ACLs are actually set on the container, so every file in a public container
 *       will return 775.
 * - stat/fstat provide only one timestamp. Swift only tracks mtime, so mtime, atime,
 *   and ctime are all set to the last modified time.
 *
 * DIRECTORIES
 *
 * OpenStack Swift does not really have directories. Rather, it allows
 * characters such as '/' to be used to designate namespaces on object
 * names. (For simplicity, this library uses only '/' as a separator).
 *
 * This allows for simulated directory listings. Requesting 
 * `scandir('swift://foo/bar/')` is really a request to "find all of the items
 * in the 'foo' container whose names start with 'bar/'".
 *
 * Because of this...
 *
 * - Directory reading functions like scandir(), opendir(), readdir()
 *   and so forth are supported.
 * - Functions to create or remove directories (mkdir() and rmdir()) are
 *   meaningless, and thus not supported.
 *
 * Swift still has support for "directory markers" (special zero-byte files
 * that act like directories). However, since there are no standards for how
 * said markers ought to be created, they are not supported by the stream
 * wrapper.
 *
 * As usual, the underlying \HPCloud\Storage\ObjectStorage\Container class
 * supports the full range of Swift features.
 *
 * SUPPORTED CONTEXT PARAMETERS
 *
 * This section details paramters that can be passed <i>either</i>
 * through a stream context <i>or</i> through
 * \HPCloud\Bootstrap::setConfiguration().
 *
 * You are <i>required</i> to pass in authentication information. This
 * comes in one of three forms:
 *
 * 1. API keys: acccount, key, tenantid, endpoint
 * 2. User login: username, password, tenantid, endpoint
 * 3. Existing (valid) token: token, swift_endpoint
 *
 * The third method (token) can be used when the application has already
 * authenticated. In this case, a token has been generated and assigneet 
 * to an account and tenant ID.
 *
 * The following parameters may be set either in the stream context
 * or through \HPCloud\Bootstrap::setConfiguration():
 *
 * - token: An auth token. If this is supplied, authentication is skipped and
 *     this token is used. NOTE: You MUST set swift_endpoint if using this
 *     option.
 * - swift_endpoint: The URL to the swift instance. This is only necessary if
 *     'token' is set. Otherwise it is ignored.
 * - username: A username. MUST be accompanied by 'password' and 'tenantid'.
 * - password: A password. MUST be accompanied by 'username' and 'tenantid'.
 * - account: An account ID. MUST be accompanied by a 'key' and 'tenantid'.
 * - key: A secret key. MUST be accompanied by an 'account' and 'tenantid'.
 * - endpoint: The URL to the authentication endpoint. Necessary if you are not
 *     using a 'token' and 'swift_endpoint'.
 * - use_swift_auth: If this is set to TRUE, it will force the app to use
 *     the deprecated swiftAuth instead of IdentityServices authentication.
 *     In general, you should avoid using this.
 * - content_type: This is effective only when writing files. It will
 *     set the Content-Type of the file during upload.
 */
class StreamWrapper {

  const DEFAULT_SCHEME = 'swift';

  /**
   * The stream context.
   *
   * This is set automatically when the stream wrapper is created by
   * PHP. Note that it is not set through a constructor.
   */
  public $context;
  protected $contextArray = array();

  protected $schemeName = self::DEFAULT_SCHEME;
  protected $authToken;


  // File flags. These should probably be replaced by O_ const's at some point.
  protected $isBinary = FALSE;
  protected $isText = TRUE;
  protected $isWriting = FALSE;
  protected $isReading = FALSE;
  protected $isTruncating = FALSE;
  protected $isAppending = FALSE;
  protected $noOverwrite = FALSE;
  protected $createIfNotFound = TRUE;

  /**
   * If this is TRUE, no data is ever sent to the remote server.
   */
  protected $isNeverDirty = FALSE;

  protected $triggerErrors = FALSE;

  /**
   * Indicate whether the local differs from remote.
   *
   * When the file is modified in such a way that 
   * it needs to be written remotely, the isDirty flag
   * is set to TRUE.
   */
  protected $isDirty = FALSE;

  /**
   * Object storage instance.
   */
  protected $store;

  /**
   * The Container.
   */
  protected $container;

  /**
   * The Object.
   */
  protected $obj;

  /**
   * The IO stream for the Object.
   */
  protected $objStream;

  /**
   * Directory listing.
   *
   * Used for directory methods.
   */
  protected $dirListing = array();
  protected $dirIndex = 0;
  protected $dirPrefix = '';

  /**
   * Close a directory.
   *
   * This closes a directory handle, freeing up the resources.
   *
   * NB: Some versions of PHP 5.3 don't clear all buffers when
   * closing, and the handle can occasionally remain accessible for
   * some period of time.
   */
  public function dir_closedir() {
    $this->dirIndex = 0;
    $this->dirListing = array();

    //syslog(LOG_WARNING, "CLOSEDIR called.");

    return TRUE;
  }

  /**
   * Open a directory for reading.
   *
   * See opendir() and scandir().
   *
   * @param string $path
   *   The URL to open.
   * @param int $options
   *   Unused.
   * @return boolean
   *   TRUE if the directory is opened, FALSE otherwise.
   */
  public function dir_opendir($path, $options) {
    $url = $this->parseUrl($path);

    if (empty($url['host'])) {
      trigger_error('Container name is required.' , E_USER_WARNING);
      return FALSE;
    }

    try {
      $this->initializeObjectStorage();
      $container = $this->store->container($url['host']);

      if (empty($url['path'])) {
        $this->dirPrefix = '';
      }
      else {
        $this->dirPrefix = $url['path'];
      }

      $sep = '/';


      $this->dirListing = $container->objectsWithPrefix($this->dirPrefix, $sep);
    }
    catch (\HPCloud\Exception $e) {
      trigger_error('Directory could not be opened: ' . $e->getMessage(), E_USER_WARNING);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Read an entry from the directory.
   *
   * This gets a single line from the directory.
   *
   * @return string
   *   The name of the resource or FALSE when the directory has no more
   *   entries.
   */
  public function dir_readdir() {
    // If we are at the end of the listing, return FALSE.
    if (count($this->dirListing) <= $this->dirIndex) {
      return FALSE;
    }

    $curr = $this->dirListing[$this->dirIndex];
    $this->dirIndex++;

    if ($curr instanceof \HPCloud\Storage\ObjectStorage\Subdir) {
      $fullpath = $curr->path();
    }
    else {
       $fullpath = $curr->name();
    }

    if (!empty($this->dirPrefix)) {
      $len = strlen($this->dirPrefix);
      $fullpath = substr($fullpath, $len);
    }
    return $fullpath;


  }

  /**
   * Rewind to the beginning of the listing.
   *
   * This repositions the read pointer at the first entry in the directory.
   */
  public function dir_rewinddir() {
    $this->dirIndex = 0;
  }

  /*
  public function mkdir($path, $mode, $options) {

  }

  public function rmdir($path, $options) {

  }
   */

  /**
   * Rename a swift object.
   *
   * This works by copying the object (metadata) and
   * then removing the original version.
   *
   * This DOES support cross-container renaming.
   *
   * See Container::copy().
   *
   * @param string $path_from
   *   A swift URL that exists on the remote.
   * @param string $path_to
   *   A swift URL to another path.
   * @return boolean
   *   TRUE on success, FALSE otherwise.
   */
  public function rename($path_from, $path_to) {
    $this->initializeObjectStorage();
    $src = $this->parseUrl($path_from);
    $dest = $this->parseUrl($path_to);

    if ($src['scheme'] != $dest['scheme']) {
      trigger_error("I'm to stupid to copy across protocols.", E_USER_WARNING);
    }

    if ( empty($src['host'])  || empty($src['path'])
      || empty($dest['host']) || empty($dest['path'])) {
        trigger_error('Container and path are required for both source and destination URLs.', E_USER_WARNING);
        return FALSE;
    }

    $container = $this->store->container($src['host']);

    $object = $container->remoteObject($src['path']);

    try {
      $ret = $container->copy($object, $dest['path'], $dest['host']);
      if ($ret) {
        return $container->delete($src['path']);
      }
    }
    catch (\HPCloud\Exception $e) {
      trigger_error('Rename was not completed: ' . $e->getMessage(), E_USER_WARNING);
      return FALSE;
    }
  }

  public function copy($path_from, $path_to) {
    throw new \Exception("UNDOCUMENTED.");
  }

  /**
   * Cast stream into a lower-level stream.
   *
   * This is used for stream_select() and perhaps others.Because it exposes
   * the lower-level buffer objects, this function can have unexpected
   * side effects.
   *
   * @return resource
   *   this returns the underlying stream.
   */
  public function stream_cast($cast_as) {
    return $this->objStream;
  }

  /**
   * Close a stream, writing if necessary.
   *
   * This will close the present stream. Importantly,
   * this will also write to the remote object storage if
   * any changes have been made locally.
   */
  public function stream_close() {

    try {
      $this->writeRemote();
    }
    catch (\HPCloud\Exception $e) {
      trigger_error('Error while closing: ' . $e->getMessage(), E_USER_NOTICE);
      return FALSE;
    }

    // Force-clear the memory hogs.
    unset($this->obj);
    fclose($this->objStream);
  }

  /**
   * Check whether the stream has reached its end.
   *
   * This checks whether the stream has reached the
   * end of the object's contents.
   *
   * See stream_seek().
   *
   * @return boolean
   *   TRUE if it has reached the end, FALSE otherwise.
   */
  public function stream_eof() {
    return feof($this->objStream);
  }

  /**
   * Initiate saving data on the remote object storage.
   *
   * If the local copy of this object has been modified,
   * it is written remotely.
   */
  public function stream_flush() {
    try {
      $this->writeRemote();
    }
    catch (\HPCloud\Exception $e) {
      syslog(LOG_WARNING, $e);
      trigger_error('Error while flushing: ' . $e->getMessage(), E_USER_NOTICE);
      return FALSE;
    }
  }

  /**
   * Write data to the remote object storage.
   *
   * Internally, this is used by flush and close.
   */
  protected function writeRemote() {

    $contentType = $this->cxt('content_type');
    if (!empty($contentType)) {
      $this->obj->setContentType($contentType);
    }

    // Skip debug streams.
    if ($this->isNeverDirty) {
      syslog(LOG_WARNING, "Never dirty. Skipping write.");
      return;
    }

    // Stream is dirty and needs a write.
    if ($this->isDirty) {
      syslog(LOG_WARNING, "Marked dirty. Writing object.");

      $position = ftell($this->objStream);

      rewind($this->objStream);
      $this->container->save($this->obj, $this->objStream);

      fseek($this->objStream, SEEK_SET, $position);

    }
    else {
      syslog(LOG_WARNING, "Not dirty. Skipping write.");
    }
    $this->isDirty = FALSE;
  }

  /*
   * Locking is currently unsupported.
   *
   * There is no remote support for locking a 
   * file.
  public function stream_lock($operation) {

  }
   */

  /**
   * Open a stream resource.
   *
   * This opens a given stream resource and prepares it for reading or writing.
   *
   * If a file is opened in write mode, its contents will be retrieved from the
   * remote storage and cached locally for manipulation. If the file is opened
   * in a write-only mode, the contents will be created locally and then pushed
   * remotely as necessary.
   *
   * During this operation, the remote host may need to be contacted for
   * authentication as well as for file retrieval.
   *
   * @param string $path
   *   The URL to the resource. See the class description for details, but
   *   typically this expects URLs in the form `swift://CONTAINER/OBJECT`.
   * @param string $mode
   *   Any of the documented mode strings. See fopen(). For any file that is
   *   in a writing mode, the file will be saved remotely on flush or close.
   *   Note that there is an extra mode: 'nope'. It acts like 'c+' except
   *   that it is never written remotely. This is useful for debugging the
   *   stream locally without sending that data to object storage. (Note that
   *   data is still fetched -- just never written.)
   * @param int $options
   *   An OR'd list of options. Only STREAM_REPORT_ERRORS has any meaning
   *   to this wrapper, as it is not working with local files.
   * @param string $opened_path
   *   This is not used, as this wrapper deals only with remote objects.
   */
  public function stream_open($path, $mode, $options, &$opened_path) {

    //syslog(LOG_WARNING, "I received this URL: " . $path);

    // If STREAM_REPORT_ERRORS is set, we are responsible for
    // all error handling while opening the stream.
    if (STREAM_REPORT_ERRORS & $options) {
      $this->triggerErrors = TRUE;
    }

    // Using the mode string, set the internal mode.
    $this->setMode($mode);

    // Parse the URL.
    $url = $this->parseUrl($path);
    //syslog(LOG_WARNING, print_r($url, TRUE));

    // Container name is required.
    if (empty($url['host'])) {
      //if ($this->triggerErrors) {
        trigger_error('No container name was supplied in ' . $path, E_USER_WARNING);
      //}
      return FALSE;
    }

    // A path to an object is required.
    if (empty($url['path'])) {
      //if ($this->triggerErrors) {
        trigger_error('No object name was supplied in ' . $path, E_USER_WARNING);
      //}
      return FALSE;
    }

    // We set this because it is possible to bind another scheme name,
    // and we need to know that name if it's changed.
    $this->schemeName = isset($url['scheme']) ? $url['scheme'] : self::DEFAULT_SCHEME;

    // Now we find out the container name. We walk a fine line here, because we don't
    // create a new container, but we don't want to incur heavy network
    // traffic, either. So we have to assume that we have a valid container
    // until we issue our first request.
    $containerName = $url['host'];

    // Object name.
    $objectName = $url['path'];


    // XXX: We reserve the query string for passing additional params.

    try {
      $this->initializeObjectStorage();
    }
    catch (\HPCloud\Exception $e) {
      trigger_error('Failed to init object storage: ' . $e->getMessage(), E_USER_WARNING);
      return FALSE;
    }

    //syslog(LOG_WARNING, "Container: " . $containerName);


    // Now we need to get the container. Doing a server round-trip here gives
    // us the peace of mind that we have an actual container.
    $this->container = $this->store->container($containerName);

    // Now we fetch the file. Only under certain circumstances to we generate
    // an error if the file is not found.
    // FIXME: We should probably allow a context param that can be set to 
    // mark the file as lazily fetched.
    try {
      $this->obj = $this->container->object($objectName);
      $stream = $this->obj->stream();
      $streamMeta = stream_get_meta_data($stream);

      // Support 'x' and 'x+' modes.
      if ($this->noOverwrite) {
        //if ($this->triggerErrors) {
          trigger_error('File exists and cannot be overwritten.', E_USER_WARNING);
        //}
        return FALSE;
      }

      // If we need to write to it, we need a writable
      // stream. Also, if we need to block reading, this
      // will require creating an alternate stream.
      if ($this->isWriting && ($streamMeta['mode'] == 'r' || !$this->isReading)) {
        $newMode = $this->isReading ? 'rb+' : 'wb';
        $tmpStream = fopen('php://temp', $newMode);
        stream_copy_to_stream($stream, $tmpStream);

        // Skip rewinding if we can.
        if (!$this->isAppending) {
          rewind($tmpStream);
        }

        $this->objStream = $tmpStream;
      }
      else {
        $this->objStream = $this->obj->stream();
      }

      // Append mode requires seeking to the end.
      if ($this->isAppending) {
        fseek($this->objStream, -1, SEEK_END);
      }
    }

    // If a 404 is thrown, we need to determine whether
    // or not a new file should be created.
    catch (\HPCloud\Transport\FileNotFoundException $nf) {

      // For many modes, we just go ahead and create.
      if ($this->createIfNotFound) {
        $this->obj = new Object($objectName);
        $this->objStream = fopen('php://temp', 'rb+');
        $this->isDirty = TRUE;
      }
      else {
        //if ($this->triggerErrors) {
          trigger_error($nf->getMessage(), E_USER_WARNING);
        //}
        return FALSE;
      }

    }
    // All other exceptions are fatal.
    catch (\HPCloud\Exception $e) {
      //if ($this->triggerErrors) {
        trigger_error('Failed to fetch object: ' . $e->getMessage(), E_USER_WARNING);
      //}
      return FALSE;
    }

    // At this point, we have a file that may be read-only. It also may be
    // reading off of a socket. It will be positioned at the beginning of
    // the stream.

    return TRUE;
  }

  /**
   * Read N bytes from the stream.
   *
   * This will read up to the requested number of bytes. Or, upon
   * hitting the end of the file, it will return NULL.
   *
   * See fread(), fgets(), and so on for examples.
   *
   * @param int $count
   *   The number of bytes to read (usually 8192).
   * @return string
   *   The data read.
   */
  public function stream_read($count) {
    return fread($this->objStream, $count);
  }

  /**
   * Perform a seek.
   *
   * IMPORTANT: Unlike the PHP core, this library
   * allows you to fseek() inside of a file opened
   * in append mode ('a' or 'a+').
   */
  public function stream_seek($offset, $whence) {
    $ret = fseek($this->objStream, $offset, $whence);

    // fseek returns 0 for success, -1 for failure.
    // We need to return TRUE for success, FALSE for failure.
    return $ret === 0;
  }

  /**
   * Set options on the underlying stream.
   *
   * The settings here do not trickle down to the network socket, which is
   * left open for only a brief period of time. Instead, they impact the middle
   * buffer stream, where the file is read and written to between flush/close
   * operations. Thus, tuning these will not have any impact on network
   * performance.
   *
   * See stream_set_blocking(), stream_set_timeout(), and stream_write_buffer().
   */
  public function stream_set_option($option, $arg1, $arg2) {
    switch ($option) {
      case STREAM_OPTION_BLOCKING:
        return stream_set_blocking($this->objStream, $arg1);
      case STREAM_OPTION_READ_TIMEOUT:
        // XXX: Should this have any effect on the lower-level
        // socket, too? Or just the buffered tmp storage?
        return stream_set_timeout($this->objStream, $arg1, $arg2);
      case STREAM_OPTION_WRITE_BUFFER:
        return stream_set_write_buffer($this->objStream, $arg2);
    }

  }

  public function stream_stat() {
    $stat = fstat($this->objStream);

    // FIXME: Need to calculate the length of the $objStream.
    //$contentLength = $this->obj->contentLength();
    $contentLength = $stat['size'];

    return $this->generateStat($this->obj, $this->container, $contentLength);
/*

    if ($this->obj instanceof \HPCloud\Storage\ObjectStorage\RemoteObject) {
      $mtime = $this->obj->lastModified();
    }
    else {
      $mtime = 0;
    }
    return array(
      0 => NULL,
      1 => NULL,
      2 => NULL,
      3 => NULL,
      4 => 0,
      5 => 0,
      6 => -1,
      7 => $contentLength,
      8 => $mtime,
      9 => $mtime,
      10 => $mtime,
      11 => -1,
      12 => -1,
      'dev' => NULL,
      'ino' => NULL,
      'mode' => NULL,
      'nlink' => NULL,
      'uid' => 0,
      'gid' => 0,
      'rdev' => -1,
      // FIXME!!!
      'size' => $contentLength,

      // All we have is modification time.
      'atime' => $mtime,
      'mtime' => $mtime,
      'ctime' => $mtime,

      'blksize' => -1,
      'blocks' => -1,
    );
 */
  }

  /**
   * Get the current position in the stream.
   *
   * See ftell() and fseek().
   *
   * @return int
   *   The current position in the stream.
   */
  public function stream_tell() {
    return ftell($this->objStream);
  }

  /**
   * Write data to stream.
   *
   * This writes data to the local stream buffer. Data
   * is not pushed remotely until stream_close() or 
   * stream_flush() is called.
   *
   * @param string $data
   *   Data to write to the stream.
   * @return int
   *   The number of bytes written. 0 indicates and error.
   */
  public function stream_write($data) {
    $this->isDirty = TRUE;
    return fwrite($this->objStream, $data);
  }

  /**
   * Unlink a file.
   *
   * This removes the remote copy of the file. Like a normal unlink operation,
   * it does not destroy the (local) file handle until the file is closed.
   * Therefore you can continue accessing the object locally.
   *
   * Note that OpenStack Swift does not draw a distinction between file objects
   * and "directory" objects (where the latter is a 0-byte object). This will
   * delete either one. If you are using directory markers, not that deleting
   * a marker will NOT delete the contents of the "directory".
   *
   * @param string $path
   *   The URL.
   * @return boolean
   *   TRUE if the file was deleted, FALSE otherwise.
   */
  public function unlink($path) {
    $url = $this->parseUrl($path);

    // Host is required.
    if (empty($url['host'])) {
      trigger_error('Container name is required.', E_USER_WARNING);
      return FALSE;
    }

    // I suppose we could allow deleting containers,
    // but that isn't really the purpose of the
    // stream wrapper.
    if (empty($url['path'])) {
      trigger_error('Path is required.', E_USER_WARNING);
      return FALSE;
    }

    try {
      $this->initializeObjectStorage();
      $container = $this->store->container($url['host']);
      return $container->delete($url['path']);
    }
    catch (\HPCLoud\Exception $e) {
      trigger_error('Error during unlink: ' . $e->getMessage(), E_USER_WARNING);
      return FALSE;
    }

  }

  public function url_stat($path, $flags) {
    $url = $this->parseUrl($path);

    if (empty($url['host']) || empty($url['path'])) {
      trigger_error('Container name (host) and path are required.', E_USER_WARNING);
      return FALSE;
    }

    try {
      $this->initializeObjectStorage();
      $container = $this->store->container($url['host']);
      $obj = $container->remoteObject($url['path']);
    }
    catch(\HPCloud\Exception $e) {
      //trigger_error('Could not stat remote file: ' . $e->getMessage(), E_USER_WARNING);
      return FALSE;
    }

    return $this->generateStat($obj, $container, $obj->contentLength());

  }

  /**
   * Get the Object.
   *
   * This provides low-level access to the
   * \PHCloud\Storage\ObjectStorage\Object instance in which the content
   * is stored.
   *
   * Accessing the object's payload (Object::content()) is strongly
   * discouraged, as it will modify the pointers in the stream that the
   * stream wrapper is using.
   *
   * HOWEVER, accessing the Object's metadata properties, content type,
   * and so on is okay. Changes to this data will be written on the next
   * flush, provided that the file stream data has also been changed.
   *
   * To access this:
   *
   * @code
   * <?php
   *  $handle = fopen('swift://container/test.txt', 'rb', $cxt);
   *  $md = stream_get_meta_data($handle);
   *  $obj = $md['wrapper_data']->object();
   * ?>
   * @endocde
   */
  public function object() {
    return $this->obj;
  }

  /**
   * Generate a reasonably accurate STAT array.
   *
   * Notes on mode:
   * - All modes are of the (octal) form 100XXX, where
   *   XXX is replaced by the permission string. Thus,
   *   this always reports that the type is "file" (100).
   * - Currently, only two permission sets are generated:
   *   * 770: Represents the ACL::makePrivate() perm.
   *   * 775: Represents the ACL::makePublic() perm.
   *
   * Notes on mtime/atime/ctime:
   * - For whatever reason, Swift only stores one timestamp.
   *   We use that for mtime, atime, and ctime.
   *
   * Notes on size:
   * - Size must be calculated externally, as it will sometimes
   *   be the remote's Content-Length, and it will sometimes be
   *   the cached stat['size'] for the underlying buffer.
   */
  protected function generateStat($object, $container, $size) {


    // This is not entirely accurate. Basically, if the 
    // file is marked public, it gets 100775, and if
    // it is private, it gets 100770.
    //
    // Mode is always set to file (100XXX) because there
    // is no alternative that is more sensible. PHP docs
    // do not recommend an alternative.
    //
    // octdec(100770) == 33272
    // octdec(100775) == 33277
    $mode = $container->acl()->isPublic() ? 33277 : 33272;

    // We have to fake the UID value in order for is_readible()/is_writable()
    // to work. Note that on Windows systems, stat does not look for a UID.
    if (function_exists('posix_geteuid')) {
      $uid = posix_geteuid();
      $gid = posix_getegid();
    }
    else {
      $uid = 0;
      $gid = 0;
    }

    if ($object instanceof \HPCloud\Storage\ObjectStorage\RemoteObject) {
      $modTime = $object->lastModified();
    }
    else {
      $modTime = 0;
    }
    $values = array(
      'dev' => 0,
      'ino' => 0,
      'mode' => $mode,
      'nlink' => 0,
      'uid' => $uid,
      'gid' => $gid,
      'rdev' => 0,
      'size' => $size,
      'atime' => $modTime,
      'mtime' => $modTime,
      'ctime' => $modTime,
      'blksize' => -1,
      'blocks' => -1,
    );

    $final = array_values($values) + $values;

    return $final;

  }

  ///////////////////////////////////////////////////////////////////
  // INTERNAL METHODS
  // All methods beneath this line are not part of the Stream API.
  ///////////////////////////////////////////////////////////////////

  protected function setMode($mode) {
    $mode = strtolower($mode);

    // These are largely ignored, as the remote
    // object storage does not distinguish between
    // text and binary files. Per the PHP recommendation
    // files are treated as binary.
    $this->isBinary = strpos($mode, 'b') !== FALSE;
    $this->isText = strpos($mode, 't') !== FALSE;

    // Rewrite mode to remove b or t:
    preg_replace('/[bt]?/', '', $mode);

    switch ($mode) {
      case 'r+':
        $this->isWriting = TRUE;
      case 'r':
        $this->isReading = TRUE;
        $this->createIfNotFound = FALSE;
        break;


      case 'w+':
        $this->isReading = TRUE;
      case 'w':
        $this->isTruncating = TRUE;
        $this->isWriting = TRUE;
        break;


      case 'a+':
        $this->isReading = TRUE;
      case 'a':
        $this->isAppending = TRUE;
        $this->isWriting = TRUE;
        break;


      case 'x+':
        $this->isReading = TRUE;
      case 'x':
        $this->isWriting = TRUE;
        $this->noOverwrite = TRUE;
        break;

      case 'c+':
        $this->isReading = TRUE;
      case 'c':
        $this->isWriting = TRUE;
        break;

      // nope mode: Mock read/write support,
      // but never write to the remote server.
      // (This is accomplished by never marking
      // the stream as dirty.)
      case 'nope':
        $this->isReading = TRUE;
        $this->isWriting = TRUE;
        $this->isNeverDirty = TRUE;
        break;

      // Default case is read/write
      // like c+.
      default:
        $this->isReading = TRUE;
        $this->isWriting = TRUE;
        break;

    }

  }

  /**
   * Get an item out of the context.
   *
   * @todo Should there be an option to NOT query the Bootstrap::conf()?
   *
   * @param string $name
   *   The name to look up. First look it up in the context, then look
   *   it up in the Bootstrap config.
   * @param mixed $default
   *   The default value to return if no config param was found.
   * @return mixed
   *   The discovered result, or $default if specified, or NULL if
   *   no $default is specified.
   */
  protected function cxt($name, $default = NULL) {

    // Lazilly populate the context array.
    if (is_resource($this->context) && empty($this->contextArray)) {
      $cxt = stream_context_get_options($this->context);

      // If a custom scheme name has been set, use that.
      if (!empty($cxt[$this->schemeName])) {
        $this->contextArray = $cxt[$this->schemeName];
      }
      // We fall back to this just in case.
      elseif (!empty($cxt[self::DEFAULT_SCHEME])) {
        $this->contextArray = $cxt[self::DEFAULT_SCHEME];
      }
    }

    // Should this be array_key_exists()?
    if (isset($this->contextArray[$name])) {
      return $this->contextArray[$name];
    }

    // Check to see if the value can be gotten from
    // \HPCloud\Bootstrap.
    $val = \HPCloud\Bootstrap::config($name, NULL);
    syslog(LOG_WARNING, 'Checking Bootstrap::config for ' . $name);
    if (isset($val)) {
      return $val;
    }

    return $default;
  }

  /**
   * Parse a URL.
   *
   * In order to provide full UTF-8 support, URLs must be
   * urlencoded before they are passed into the stream wrapper.
   *
   * This parses the URL and urldecodes the container name and
   * the object name.
   *
   * @param string $url
   *   A Swift URL.
   * @return array
   *   An array as documented in parse_url().
   */
  protected function parseUrl($url) {
    $res = parse_url($url);


    // These have to be decode because they will later
    // be encoded.
    foreach ($res as $key => $val) {
      if ($key == 'host') {
        $res[$key] = urldecode($val);
      }
      elseif ($key == 'path') {
        if (strpos($val, '/') === 0) {
          $val = substr($val, 1);
        }
        $res[$key] = urldecode($val);

      }
    }
    return $res;
  }

  /**
   * Based on the context, initialize the ObjectStorage.
   *
   * The following parameters may be set either in the stream context
   * or through \HPCloud\Bootstrap::setConfiguration():
   *
   * - token: An auth token. If this is supplied, authentication is skipped and
   *     this token is used. NOTE: You MUST set swift_endpoint if using this
   *     option.
   * - swift_endpoint: The URL to the swift instance. This is only necessary if
   *     'token' is set. Otherwise it is ignored.
   * - username: A username. MUST be accompanied by 'password' and 'tenantid'.
   * - password: A password. MUST be accompanied by 'username' and 'tenantid'.
   * - account: An account ID. MUST be accompanied by a 'key' and 'tenantid'.
   * - key: A secret key. MUST be accompanied by an 'account' and 'tenantid'.
   * - endpoint: The URL to the authentication endpoint. Necessary if you are not
   *     using a 'token' and 'swift_endpoint'.
   * - use_swift_auth: If this is set to TRUE, it will force the app to use
   *     the deprecated swiftAuth instead of IdentityServices authentication.
   *     In general, you should avoid using this.
   *
   * To find these params, the method first checks the supplied context. If the 
   * key is not found there, it checks the Bootstrap::conf().
   */
  protected function initializeObjectStorage() {

    $token = $this->cxt('token');
    $endpoint = $this->cxt('swift_endpoint');

    $username = $this->cxt('username');
    $password = $this->cxt('password');

    $account = $this->cxt('account');
    $key = $this->cxt('key');

    $tenantId = $this->cxt('tenantid');
    $authUrl = $this->cxt('endpoint');


    // If context has the info we need, start from there.
    if (!empty($token) && !empty($endpoint)) {
      $this->store = new \HPCloud\Storage\ObjectStorage($token, $endpoint);
    }
    elseif ($this->cxt('use_swift_auth', FALSE)) {

      if (empty($authUrl) || empty($account) || empty($key)) {
        throw new \HPCloud\Exception('account, endpoint, key are required stream parameters.');
      }
      $this->store = \HPCloud\Storage\ObjectStorage::newFromSwiftAuth($account, $key, $authUrl);

    }
    // If we get here and tenant ID is not set, we can't get a container.
    elseif (empty($tenantId) || empty($authUrl)) {
      throw new \HPCloud\Exception('Tenant ID (tenantid) and endpoint are required.');
    }
    // Try to authenticate and get a new token.
    else {
      $ident = new \HPCloud\Services\IdentityServices($authUrl);

      if (!empty($username) && !empty($password)) {
        $token = $ident->authenticateAsUser($username, $password, $tenantId);
      }
      elseif (!empty($account) && !empty($key)) {
        $token = $ident->authenticateAsAccount($account, $key, $tenantId);
      }
      else {
        throw new \HPCloud\Exception('Either username/password or account/key must be provided.');
      }

      $catalog = $ident->serviceCatalog(ObjectStorage::SERVICE_TYPE);
      if (empty($catalog) || empty($catalog[0]['endpoints'][0]['publicURL'])) {
        throw new \HPCloud\Exception('No object storage services could be found for this tenant ID.' . print_r($catalog, TRUE));
      }
      $serviceURL = $catalog[0]['endpoints'][0]['publicURL'];

      $this->store = new ObjectStorage($token, $serviceURL);
    }

    return !empty($this->store);

  }

}