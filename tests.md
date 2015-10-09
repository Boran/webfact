Manual tests of functionality
-----------------------------
Non of this is automated yet, but it explian how certian features should work, allowing manual testing and ppossible later automation.
It needs to be completed, very early dys yet..

Prepare test website

 * We will use a test website, lets call it vanilla. If there is already a vanilla container, kill it ("manage > delete everything")
 * "prepare a new website": vanilla, template "plain drupal 7". Create the website: "Manage>Create".  Check that it builds ok (status 100, look at logs),  "Inspect" it to see the MYSQL and VIRTUAL environment variables. Check that the mysql variables are also in Meta data > advanced.
 * Login to that website as admin/admin and create a content page (verify that it works).

Rebuild website

 * With the prepared website running as above, do "Advanced > Rebuild Container"
 * View the target website, verify that the example content (created in the preparation step) is still there.
 * Login to the website, verify login works and one can create content.
 * Inspect the contain and metadata, verify the mysql settings stayed the same. VIRTUAL_HOST should contain the container name

Rename a website

 * With the prepared website running as above, do "Advanced > Rename Container", choose the name "vanilla2"
 * Verify that the website name has changed to vanilla2. You will not be able to login to the site yet, since the VIRTUAL_HOST still contains the old vanilla.
 * Rebuild: "Advanced > Rebuild Container"
 * Login to the website (which will now has url prefix vanilla2), verify that the example content (created in the preparation step) is still there.

