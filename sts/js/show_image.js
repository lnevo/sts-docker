/*
 * Rolling-stock photo popup (replaces the inline script that show_image.php used to print).
 * Markup: <td data-sts-click="showImage" data-car-id="12" data-marks="ABC 1"> (empty car id = no photo).
 * show_image(car_id, reporting_marks) stays available as a global for page scripts.
 */
(function () {
  'use strict';

  function show_image(car_id, reporting_marks)
            {
              if (car_id.length > 0)
              {
                // find the upper right corner of the browser window
                var upper_right_x = window.screenX + window.parent.outerWidth;
                var upper_right_y = window.screenY;
              
                // calculate the upper left corner of the new window
                var upper_left_x = upper_right_x - 700;
                var upper_left_y = upper_right_y + 30;
              
                // get a random number and attach it to the image URL to force the browser to not use a cached image
                var date = new Date();
                var time = date.getTime();
              
                // open the window
                var img_window = window.open("", "image_window", "width=660,height=500,left=" + upper_left_x + ",top=" + upper_left_y);
              
                // check to see if the first image exists on the server
                var image1 = false;
                if (image_exists("./ImageStore/DB_Images/RollingStock/" + car_id + ".jpg?" + time))
                {
                  image1 = true;
                }

                // check to see if the second image exists on the server
                var image2 = false;
                if (image_exists("./ImageStore/DB_Images/RollingStock/" + car_id + "b.jpg?" + time))
                {
                  image2 = true;
                }

                if (image1)
                {
                  var image1_link = "./ImageStore/DB_Images/RollingStock/" + car_id + ".jpg?" + time;
                }
              
                if (image2)
                {
                  var image2_link = "./ImageStore/DB_Images/RollingStock/" + car_id + "b.jpg?" + time;
                }
              
                var window_text = "<html><head><title>" + reporting_marks + "</title></head><body>";

                if (image1 && image2)
                {
                  window_text = window_text + '<p style="font: normal 20px Verdana, Arial, sans-serif;">Two Images Available</p>';
                }
                else
                {
                  window_text = window_text + '<p style="font: normal 20px Verdana, Arial, sans-serif;">One Image Available</p>';
                }
              
                if (image1)
                {
                  window_text = window_text + '<img src="' + image1_link + '" style="width:100%;height:auto;">';
                }
              
                if (image2)
                {
                  window_text = window_text + '<img src="' + image2_link + '" style="width:100%;height:auto;">';
                }
              
                window_text = window_text + '</body></html>';
  //alert("Window Text: [" + window_text + "]");
                img_window.document.write(window_text);
              }
              else
              {
                alert("No photo available for " + reporting_marks);
              }
            }
          
            // this function tests to see if a file exists on the server and returns true if it does
            function image_exists(image_url)
            {
              var http = new XMLHttpRequest();

              http.open("HEAD", image_url, false);
              http.send();

              return http.status != 404;
            }

  window.show_image = show_image;
  window.image_exists = image_exists;

  // Register without depending on script order relative to sts-actions.js.
  var STS = (window.STS = window.STS || {});
  var handlers = STS._handlers || (STS._handlers = {});
  handlers.showImage = function (event, el) {
    show_image(el.getAttribute('data-car-id') || '', el.getAttribute('data-marks') || '');
  };
})();
