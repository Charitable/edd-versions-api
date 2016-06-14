# EDD Versions API

This plugin provides a new endpoint for the Easy Digital Downloads REST API: `versions`.

You can use this endpoint to retrieve an array with all downloads and their current version: 

```
wp_remote_get( 'https://example.com/edd-api/versions/' );
```

You can also POST to this endpoint with an array of licenses in the body of the request to return an array of downloads with their current version and the update details for any licensed downloads: 

```
wp_remote_post( 
    'https://example.com/edd-api/versions/', 
    array( 
        'body' => array(
            'licenses' => array('license1', 'license2', 'license3')
        )
    ) 
);
```