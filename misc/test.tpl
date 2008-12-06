test

Should be escaped:

{$test}
{echo $test}
{echo '&something'}
{url 'my&url'}
{echo someting}
{$test-html['something']}

Should be escaped inside the loop

{foreach from=$myvar item=myitem-html}
{$myitem-html}
{$myitem}
{$myitem['some&thing']}
{$myitem[something]}
{/foreach}

Not escaped:

{$test-html}
{$test['something-html']}
{$test[something-html]}
{foreach from=$myvar item=myitem-html}
{$myitem['some&thing-html']}
{$myitem[something-html]}
{/foreach}

Mixed:
{$something-html . $elsewhere}
{$something-html . '&somethingelse'}
