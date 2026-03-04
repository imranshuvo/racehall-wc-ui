WRMCB=function(e){var c=console;if(c&&c.log&&c.error){c.log('Error running batched script.');c.error(e);}}
;
try {
/* module-key = 'com.atlassian.confluence.plugins.confluence-view-file-macro:view-file-macro-embedded-file-view-resources', location = '/js/services/file-service.js' */
define("vfm/services/file-service",["ajs","jquery"],function(b,c){return{getCommentCount:function(d,e){var a="/rest/files/1.0/files/content/{0}/commentCount?attachmentId={1}",a=b.contextPath()+b.format(a,d,e);return c.get(a)}}});
}catch(e){WRMCB(e)};
;
try {
/* module-key = 'com.atlassian.confluence.plugins.confluence-view-file-macro:view-file-macro-embedded-file-view-resources', location = '/js/components/embedded-file-view.js' */
define("vfm/components/embedded-file-view","jquery backbone ajs confluence confluence/dark-features vfm/view-file-macro-utils".split(" "),function(f,n,i,g,o,j){return{render:function(c){var c=f(c.el),a=c.find(".confluence-embedded-image, .confluence-embedded-file");if(a.hasClass("unknown-attachment")||a.attr("src")&&a.attr("src").indexOf("/confluence/plugins/servlet/confluence/placeholder/unknown-attachment")>=0)return this;var b="",e="";if(a.attr("data-linked-resource-type")==="attachment"){a.attr("data-linked-resource-container-id");
a.attr("data-file-src");a.attr("data-linked-resource-id");a.attr("data-linked-resource-default-alias")}var h=a.hasClass("confluence-embedded-image"),d=c.parent().is("a"),k=a.attr("data-has-thumbnail")==="true";if(h)b="image/png";else{b=a.attr("data-mime-type");e=a.attr("data-nice-type")!=="null"?a.attr("data-nice-type"):"generic file"}if(d)d="";else{var d="",l=a.attr("data-linked-resource-container-id"),m=a.attr("data-linked-resource-id");if(l&&m){a=a.attr("data-unresolved-comment-count");a=parseInt(a,
10);a=f.isNumeric(a)?a:0;a=a>9?"9+":a+"";a!=="0"&&(d=g.ViewFileMacro.Templates.overlayEmbeddedFileCommentCount({commentCountRep:a}))}}b=!h&&k?g.ViewFileMacro.Templates.overlayEmbeddedFileFileTypeDesc({fileType:j.getFileTypeTextFromNiceType(e),iconClass:i.Confluence.FileTypesUtils.getAUIIconFromMime(b)}):"";if(!d&&!b)return this;e=f(g.ViewFileMacro.Templates.overlayEmbeddedFile());e.append(d).append(b);b&&c.addClass("has-comment-overlay");c.append(e)}}});
}catch(e){WRMCB(e)};