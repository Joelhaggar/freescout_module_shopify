(function () {
    var module_routes = [
    {
        "uri": "shopify\/ajax",
        "name": "shopify.ajax"
    }
];

    if (typeof(laroute) != "undefined") {
        laroute.add_routes(module_routes);
    } else {
        contole.log('laroute not initialized, can not add module routes:');
        contole.log(module_routes);
    }
})();