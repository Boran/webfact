<div id="websites-app">

    <div class="row" ng-controller="WebsitesController">
      <div class="col-sm-6 col-md-4" ng-repeat="website in websites">
        <div class="thumbnail">
          <img src="{{website.node.field_image}}">
          <div class="caption">
            <h4>{{website.node.title}}</h4>
            <hr>
            <p>Website: {{website.node.Website}}</p>
            <p>Status: {{website.node.Status}}</p>
            <p>Owner: {{website.node.Owner}}</p>
          </div>
        </div>
      </div>
    </div>

</div>
