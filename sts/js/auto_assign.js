(function () {
    function toggle_all_checkboxes(source)
    {
      var checkboxes = document.getElementsByClassName('pu_car');
      for(var i=0, n=checkboxes.length;i<n;i++)
      {
        checkboxes[i].checked = source.checked;
      }
    }
  
  STS.action('toggle_all_checkboxes', function (e, el) { toggle_all_checkboxes(el); });
})();
