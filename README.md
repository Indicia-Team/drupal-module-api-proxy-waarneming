# drupal-module-api-proxy-waarneming

A plugin for the [API Proxy](https://www.drupal.org/project/api_proxy) module.

It adds authentication for calls to the 
[Waarneming API](https://waarneming.nl/api/docs) In particular, it is intended 
for accessing the image classifier, providing filters for the
response, and appending taxa information from the Indicia warehouse.

## Configuration
After installing the module and its dependencies go to Configuration > Web 
services > API Proxy and select the Settings tab.


Even if you are going to call the module from the Drupal site hosting the
module, you will have to add the domain of your client in the CORS settings.
Failing to do so results in a 400 Bad Request error. We better tick the box to
enable POST requests too

In the Authentication section, add the client ID, email and password that you
will have set up which allow you to access the Waarneming API.

The Classification section allows you to adjust the response sent by the proxy
from a request to the image classifier. When sent an image, the Waarneming API
returns a list of possible identifications with a probability value for each. 
* You can set a probability threshold so less certain suggestions are filtered
out. 
* You can limit the returned suggestions to certain species groups. The
classifier does not use this information to refine what it does. The module
just removes any suggestions that are not in the selected groups.
* You can limit the number of suggestions returned.

The proxy can also add extra information from an Indicia warehouse. It sends
the species names from the classifier to the warehouse and looks them up in a
species list which you can specify in the Inidicia Lookup section. It obtains
the preferred name and taxa_taxon_list_id.

## Permissions
Go to Configuration > People > Permissions and find the section for the API
Proxy. A new permission has been added for using the Waarneming API. Initially,
it is only allowed to the Administrator role. Check the roles who should be
allowed access to the API. If you are not granted permission you will receive a
403 Forbidden response.

## Requests
To make a request to the image classifier, send a POST request to 
`api-proxy/waarneming` relative to the Drupal site hosting the module. To retain
flexibility in the proxy to access other endpoints of the Waarneming API, you
supply a parameter in the url with key, `_api_proxy_uri` and a value which is
the url-encoded enpoint of the Waarneming API. The endpoint of the image 
classifier is `identify-proxy/v1/?app_name=uni-jena`. I.e the full url to post
to the image classifier is `https://<your domain>/api-proxy/waarneming?_api_proxy_uri=identify-proxy%2Fv1%2F%3Fapp_name%3Duni-jena`

The body of the POST must contain an element with key, `image` and a value which
locates an image file. It can be 
* the name of a file uploaded to the interim image folder on the Drupal server,
* a url to a web-accessible image.
Send it as x-www-form-urlencoded.

An example implementation of calling the service using JavaScript and JQuery
from a page of the Drupal website looks as follows:
```
  var url = new URL('api-proxy/waarneming', location);
  url.search = '?_api_proxy_uri='
    + encodeURIComponent('identify-proxy/v1/?app_name=uni-jena');
  var data = {
    'image': 'https://inaturalist-open-data.s3.amazonaws.com/photos/156517600/original.jpeg'
  };
  return jQuery.post(url.href, data);
```

# Response
The response is an array of suggested identifications. If no species match the
criteria the array will be empty. A good match will return a single record as in
the following example.
The groups are defined at https://waarneming.nl/api/v1/species-groups/

```
[
    {
        "classifier_id": "1641@WRN",
        "classifier_name": "Mimas tiliae",
        "probability": 0.999816358089447,
        "group": 8,
        "preferred_name": "Mimas tiliae",
        "taxa_taxon_list_id": "457162",
        "taxon_meaning_id": "218594"
    }
]
```
The preferred_name, taxa_taxon_list_id, and taxon_meaning_id come from the 
indicia warehouse lookup. They are absent if no look up is required or there is
an error in the warehouse response.