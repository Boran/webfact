(function(){
  var websites = angular.module('websites', []);

  jQuery(document).ready(function () {
    angular.bootstrap(document.getElementById('websites-app'), ['websites']);
  });

  websites.controller('WebsitesController', function($scope, $http) {
    //$http.get('http://localhost/json/websites')
    $http.get('http://webfact7.webfact.corproot.net/json/websites')
      .success(function(result) {
        $scope.websites = (function() {
          return result.nodes;
        })();
      });
  });

})();
