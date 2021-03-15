# URL Shortener

This project is a URL shortener web application which also provides a basic API. This can be used to host your own URL shortener and have basic analytics on the shortened URL. Basic caching is also done using **memcached**

# How to get it to work
For the sake of simplicity, the entire repo can be run in a container and the required configuration is also provided in the repo. 

> check **docker-compose.yml** for more info

Requirements - **Docker** obviously.

In the project folder run the following commands to build and start the containers.

    docker-compose build
This fetches the necessary images and builds the service and tags them. This might run for about 5min. (It ran for about 4-5min on a good MacBook pro - #context)

    docker-compose up -d
This command builds, (re)creates, starts, and attaches to containers for a service. Basically, starts the service.

Once the docker service is up and running, you can access the service via **localhost**. Just type http://localhost/app in you browser address bar and you should see a webpage with option to create a shortened URL. 

Hurray! The URL Shortener service is up and running. (If not, please create an issue.) 


# Web Application
## Link Creation
The URL Shortener web application can be accessed by **http://localhost/app**

<img width="774" alt="Screen Shot 2021-03-15 at 12 26 16 PM" src="https://user-images.githubusercontent.com/17258137/111187419-569c5880-858a-11eb-8fda-c31575ebc3d4.png">

<img width="774" alt="Screen Shot 2021-03-15 at 12 32 25 PM" src="https://user-images.githubusercontent.com/17258137/111187605-864b6080-858a-11eb-824d-3f36c0a16397.png">

### Conditions
**URL**  - Say you want shortened URL for apple.com, you have to specify either

 - https://www.apple.com
 - https://apple.com
 - www.apple.com - This will result in an error

**Custom Hook** -*OPTIONAL* - You can add your own custom hook for the shortened URL. And the length of custom hook should be 8 characters. if the hook provided is more than 8 characters, the first 8 chars will be used. E.g. If you mentioned custom hook to be "**Cambridge**" the resulting shortened URL will be  **http://localhost/Cambridg** . Passing anything less than 8 characters will result in an error.

**Expiration Date**-*OPTIONAL* - As the name suggests, you can specify till when your URL can be accessed. You select the date and time and until then the link will be active. After that the shortened link will not work. 

## Link Stats
The stats are implemented in a basic way and can be expanded further in the future to include more data points. Stats page for above created link with hook "**Cambridg**" will look like **http://localhost/app/Cambridg/stats** and the data will be shown as below.

<img width="1163" alt="Screen Shot 2021-03-15 at 1 09 50 PM" src="https://user-images.githubusercontent.com/17258137/111192769-d1b43d80-858f-11eb-8ba2-c9dc73d53072.png">

**NOTE**: Stats table will only have data for 1st 100 visits. 
The plan is to expand this by adding pagination and updating the API endpoint to take limit and offset params and return appropriate data.

When an invalid hook is passed in url say **http://localhost/app/{invalid_hook}/stats** no data will be shown

<img width="1179" alt="Screen Shot 2021-03-15 at 1 14 53 PM" src="https://user-images.githubusercontent.com/17258137/111193349-746cbc00-8590-11eb-9916-8fb981693fc6.png">

## Link Redirection
Making any kind of request (GET, DELETE, POST, PUT, PATCH, OPTIONS) to http://localhost/{hook} would redirect to original specified URL. This will also cache the url details, so as to avoid backend DB to call to fetch the URL details. 

# API
Now the fun part, the API. The API has 3 endpoints and their functionality is as follows.

## POST  - http://localhost/api
Endpoint http://localhost/api accepts only POST. This endpoint can be used to create Shortened URL's by posting JSON objects.

Sample HTTP Request looks like this

    POST /api HTTP/1.1
    Host: localhost
    Content-Type: application/json
    Content-Length: 119
    
    {
        "url": "https://www.example.com/",
        "expiration_date": "2021-03-15 12:33:13",
        "custom_hook": "Cambridge"
    }

Just like in the web application, even in this request **custom_hook** and **expiration_date** are optional. 

**Conditions**
1. Conditions for **custom_hook** are same as that of UI, cannot be less than 8 characters and anything more than 8 characters will be trimmed down to 8 characters
2. **expiration_date** follows MySQL datetime format i.e. should be specified like this "**2021-03-15 12:33:13**" and should set in future i.e. should not be past time i.e. you know what I mean!
3. **Content-Type** for the request SHOULD be set to **application/json**

if you follow these conditions properly and send a request, a successful response will look like below. 

    {
	    "url":  "http://localhost/Cambridg"
    }

Sample request without **custom_hook** will generate a shortened URL with a hook made of random alpha-numeric string of length 8.

    POST /api HTTP/1.1
    Host: localhost
    Content-Type: application/json
    Content-Length: 39
    
    {
        "url": "https://www.apple.com/"
    }
**Response**

    {
	    "url":  "http://localhost/1cee4a24"
    }


We can also create multiple shortened URLs by passing in an array. In the following request, the first URL in the array is good without errors, and the second one has invalid hook (less than 8)

**Request Body**

    [
	    { 
		    "url":"https://www.apple.com"
	    },{
		    "url":"https://www.iformbuilder.com",
		    "custom_hook":"iformb"
	    }
    ]

**Response**

    [
        {
            "url": "http://localhost/6548c987"
        },
        {
            "statusCode": 400,
            "error": {
                "type": "INVALID_PARAMETER",
                "message": "Invalid parameter custom_hook"
            }
        }
    ]

### Error Response Structure and Status codes
The error response structure looks as following example

    {
        "statusCode": 400,
        "error": {
            "type": "INVALID_PARAMETER",
            "message": "Invalid parameter custom_hook"
        }
    }

 - **201 Created** - When an shortened URL is correctly created
 - **400 Bad Request** - When there is an error in the body sent
 - **415 Unsupported Media Type** - When anything other than a Content-Type:application/json is sent.
 - **500 Internal Server Error** - When there is an issue with executiong the request.
 - **207 Multi-Status** - This is returned when you try to create more than one shortened URL's by sending an array and some of then fail and some of them are created. 

## DELETE  - http://localhost/api/{hook}
As the name suggests, this endpoint is used to delete a shortened URL. Sample request will look like below.

    DELETE /api/001365de HTTP/1.1
    Host: localhost

In the above request, **001365de** is the hook for the shortened URL. If the hook is valid i.e. if the shortened URL exists, the response will be a URL returned that will not longer work with status **200 OK**

    {
        "url": "http:\/\/localhost\/001365de"
    }
If the shortened URL doesn't exist for the hook, the following error object is returned with status **400 Bad Request**

    {
        "statusCode": 400,
        "error": {
            "type": "NOT_FOUND",
            "message": "URL not found."
        }
    }

**POSSIBLE UPGRADES:**
Currently, the DELETE is open and anyone with the right knowledge and hook can delete the shortened URL. So one possible upgrade is to generate a random token of say length 20, on shortened URL creation and give it back to the user to store. We can call it **secret_token** may be. When the user who created the shortened URL wants to delete the URL, then make a DELETE call using the **secret_token** instead of the **hook**. This way, only the user that created the URL will be able to delete it.

## GET  - http://localhost/api/{hook}/stats
In simple terms, this endpoint print's out the JSON version if stats page. This is a very basic version of stats which gives out data for number of visits i.e. number of times the link was used, when was it created and list of first 100 stats of link visits. You can say that this is more of a POC than a full fledged endpoint. A sample request will look as follows

    GET /api/hikklmn/stats HTTP/1.1
    Host: localhost

in the above request, **hikklmn** is the hook for the shortened URL. If the hook is valid i.e. if the shortened URL exists, the response will be as follows with status **200 OK**

    {
        "visits": 81,
        "creation_date": "2021-03-12 08:47:24",
        "data": [
            {
                "from_addr": "192.168.192.1",
                "browser_info": "Chrome",
                "referrer": "",
                "os_info": "Mac OS X x32"
            },
            {
                "from_addr": "192.168.192.1",
                "browser_info": "Chrome",
                "referrer": "",
                "os_info": "Mac OS X x32"
            },
            {
                "from_addr": "192.168.192.1",
                "browser_info": "Chrome",
                "referrer": "",
                "os_info": "Mac OS X x32"
            },
            {
                "from_addr": "192.168.192.1",
                "browser_info": "Chrome",
                "referrer": "",
                "os_info": "Mac OS X x32"
            }
        ]
    }

If the hook is not found, the following error is returned with status **404 Not Found**.

    {
        "statusCode": 404,
        "error": {
            "type": "RESOURCE_NOT_FOUND",
            "message": "Not found."
        }
    }

**POSSIBLE UPGRADES:**
1. We can definitely expand on the attributes on which we collect data instead of just limiting to from_addr, browser_info, referrer and os_info. 
2. We can add LIMIT and OFFSET params to the endpoint to fetch more than 100 stat entries.


# TEST CASES
Now to the nail-biting-and-praying-everything-works part.

## Web Application
### http://localhost/app - Create Shortened URL page

 - [ ] Input a valid URL in the format and click "Get Shortened URL" button and you should get a success box with shortened URL in it like shown below.
 <img width="623" alt="Screen Shot 2021-03-15 at 2 58 17 PM" src="https://user-images.githubusercontent.com/17258137/111206557-e64c0200-859e-11eb-8242-03ee461e77af.png">
 
 - [ ] In the URL input, use the same URL that you just used to create a shortened URL and click "Get Shortened URL" button,  you should get an error saying "**Invalid parameter url - Already Exists**" in a warning box as shown below.
 -<img width="610" alt="Screen Shot 2021-03-15 at 3 01 23 PM" src="https://user-images.githubusercontent.com/17258137/111206864-55295b00-859f-11eb-904b-a80a82852f9a.png">

 - [ ] When you enter an invalid URL to the input and click "Get Shortened URL" button, you should get an error saying "**Invalid parameter url - Not well formed**" in a warning box as shown below.
	 - [ ] input - **www.example.com** - Invalid parameter url - Not well formed
	 - [ ] input - **example.com** - Invalid parameter url - Not well formed
	 - [ ] input - **example** - Invalid parameter url - Not well formed
<img width="628" alt="Screen Shot 2021-03-15 at 3 04 46 PM" src="https://user-images.githubusercontent.com/17258137/111208101-b998ea00-85a0-11eb-9bb6-50c1584c14cc.png">
 
 - [ ] When you enter a URL that is not reachable or doesn't exist, and click "Get Shortened URL" button,  you should get an error saying "**Invalid parameter url - Not reachable**" in a warning box as shown below.
<img width="620" alt="Screen Shot 2021-03-15 at 3 14 30 PM" src="https://user-images.githubusercontent.com/17258137/111208540-404dc700-85a1-11eb-92a1-1025ff06cfb4.png">

 - [ ] When you enter a valid URL and select "**Add Custom Hook**" and add a valid hook and click "Get Shortened URL" button,  you should get a success box with shortened URL using custom hook in it like shown below.
<img width="600" alt="Screen Shot 2021-03-15 at 3 28 44 PM" src="https://user-images.githubusercontent.com/17258137/111210067-2a410600-85a3-11eb-8443-6a76dcb6c5f7.png">

 - [ ] When you enter a valid URL and select "**Add Custom Hook**" and add a **INVALID** hook and click "Get Shortened URL" button,  you should get an error saying "**Invalid parameter url - Not reachable**" in a warning box as shown below. INVALID hook can be three types
   - 1. Hook that is already used.
   - 2. Hook length less than 8 characters.
   - 3. Hook is empty.
<img width="619" alt="Screen Shot 2021-03-15 at 3 30 41 PM" src="https://user-images.githubusercontent.com/17258137/111210313-7a1fcd00-85a3-11eb-9816-19e3057b2825.png">
<img width="619" alt="Screen Shot 2021-03-15 at 3 33 07 PM" src="https://user-images.githubusercontent.com/17258137/111210576-c703a380-85a3-11eb-9131-29b627cbd136.png">

 - [ ] When you enter a valid URL and select "**Add Expiration**" and add an **INVALID** date and click "Get Shortened URL" button,  you should get an error saying "**Invalid parameter expiration_date**" in a warning box as shown below.  INVALID expiration date is whe you select a datetime from past.
 <img width="636" alt="Screen Shot 2021-03-15 at 3 38 21 PM" src="https://user-images.githubusercontent.com/17258137/111211580-f070ff00-85a4-11eb-9c27-d2bee607877b.png">


### http://localhost/app/{hook}/stats - Stats Page

 - [ ] When accessing stats page, in the hook, if you pass a valid hook, you should get HTML page like below.
 <img width="1284" alt="Screen Shot 2021-03-15 at 3 46 35 PM" src="https://user-images.githubusercontent.com/17258137/111212163-a5a3b700-85a5-11eb-80f1-1093cdac7ca2.png">
 
 - [ ] When accessing stats page, in the hook, if you pass an invalid hook i.e a hook that doesn'e exist, you should get HTML page like below.
<img width="1182" alt="Screen Shot 2021-03-15 at 3 48 09 PM" src="https://user-images.githubusercontent.com/17258137/111212356-e00d5400-85a5-11eb-8f51-338ceb7d3ac3.png">

## API
### POST - http://localhost/api

 - [ ] POST valid **`url`** - Get back shortened URL with status 201
   - Sample Request Body

    >     {
    >     	    "url":  "https://www.apple.com"
    >     }
    
    - Sample Response

    >     {
    >         "url": "http:\/\/localhost\/8012874f"
    >     }

 - [ ] POST an Invalid **`url`** - Get back error with status 400
   - [ ]    e.g url -  **[www.example.com](http://www.example.com/)**  - Invalid parameter url - Not well formed
   - [ ]    e.g url -  **[example.com](http://example.com/)**  - Invalid parameter url - Not well formed
   - [ ]    e.g url -  **example**  - Invalid parameter url - Not well formed
   - Response should be following with Status **400 Bad request**
    >        {
    >         "statusCode": 400,
    >         "error": {
    >             "type": "INVALID_PARAMETER",
    >             "message": "Invalid parameter url - Not well formed"
    >         }
    >     }

 - [ ] POST valid **`url`**  and **`custom_hook`** - Get back shortened URL with status 201
   - Sample Request Body

    >     {
    >     	    "url":  "https://www.apple.com",
    > 				"custom_hook":"gotoapple"
    >     }
    
    - Sample Response

    >     {
    >         "url": "http:\/\/localhost\/8012874f"
    >     }

 - [ ] POST an Invalid **`custom_hook`** - Get back error with status 400
   - [ ]    e.g custom_hook -  string having less than 8 characters.
   - [ ]    e.g custom_hook -  string that is already in use.
   - Response should be following with Status **400 Bad request**
    >        {
    >         "statusCode": 400,
    >         "error": {
    >             "type": "INVALID_PARAMETER",
    >             "message": "Invalid parameter custom_hook"
    >         }
    >     }

 - [ ] POST valid **`url`**  and **`custom_hook`**  and **`expiration_date`** - Get back shortened URL with status 201
   - Sample Request Body

    >     {
    >     	    "url":  "https://www.apple.com",
    > 				"custom_hook":"gotoapple",
    >				"expiration_date":"2021-03-15 12:33:13"
    >     }
    
    - Sample Response

    >     {
    >         "url": "http:\/\/localhost\/8012874f"
    >     }

 - [ ] POST an Invalid **`expiration_date`** - Get back error with status 400
   - [ ]    e.g expiration_date -  Date and time from past.
   - Response should be following with Status **400 Bad request**
    >        {
    >         "statusCode": 400,
    >         "error": {
    >             "type": "INVALID_PARAMETER",
    >             "message": "Invalid parameter expiration_date"
    >         }
    >     }


### DELETE - http://localhost/api/{hook}
- [ ] DELETE request to a valid **`hook`** i.e. hook that exists - get back shortened URL with status 200. The returned URL doesn't work since it has just been deleted.
  - Sample Request

    >     DELETE /api/gotoappl HTTP/1.1
    >     Host: localhost

  - Sample response

    >     {
    >     	"url":  "http://localhost/gotoappl"
    >     }

   - [ ] DELETE request to a invalid **`hook`** i.e. hook that doesn't exists - get back error with status 404. 
  - Sample Request

    >     DELETE /api/gotoappl HTTP/1.1
    >     Host: localhost

  - Sample response

    >     {
    >         "statusCode": 404,
    >         "error": {
    >             "type": "NOT_FOUND",
    >             "message": "URL not found."
    >         }
    >     }

### GET - http://localhost/api/{hook}/stats
- [ ] GET request to a valid **`hook`** i.e. hook that exists - get back shortened URL with status 200. The returned URL doesn't work since it has just been deleted.
  - Sample Request

    >     GET /api/hikklmn/stats HTTP/1.1
    >     Host: localhost

  - Sample response

    >     {
    >         "visits": 14,
    >         "creation_date": "2021-03-12 08:47:24",
    >         "data": [
    >             {
    >                 "from_addr": "192.168.192.1",
    >                 "browser_info": "Chrome",
    >                 "referrer": "",
    >                 "os_info": "Mac OS X x32"
    >             },
    >             {
    >                 "from_addr": "192.168.192.1",
    >                 "browser_info": "Chrome",
    >                 "referrer": "",
    >                 "os_info": "Mac OS X x32"
    >             },
    >             {
    >                 "from_addr": "192.168.192.1",
    >                 "browser_info": "Chrome",
    >                 "referrer": "",
    >                 "os_info": "Mac OS X x32"
    >             },
    >             {
    >                 "from_addr": "192.168.192.1",
    >                 "browser_info": "Chrome",
    >                 "referrer": "",
    >                 "os_info": "Mac OS X x32"
    >             } ....
    >				......
    > 				......
    >         ]
    >     }

   - [ ] GET request to a invalid **`hook`** i.e. hook that doesn't exists - get back error with status 404. 
  - Sample Request

    >     GET /api/hsrtmn/stats HTTP/1.1
    >     Host: localhost

  - Sample response

    >     {
    >         "statusCode": 404,
    >         "error": {
    >             "type": "NOT_FOUND",
    >             "message": "URL not found."
    >         }
    >     }
