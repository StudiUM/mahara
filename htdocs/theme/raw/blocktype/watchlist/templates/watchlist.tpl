{if $watchlistempty}
    {str tag=nopages section=blocktype.watchlist}
{else}
<table id="watchlistblock" class="viewlist fullwidth">
    {foreach $views as item=view}
        <tr>
            <td class="{cycle values='r0,r1'}">
                <h4 class="title"><a href="{$view->fullurl}" class="watchlist-showview">{$view->title}</a></h4>
            </td>
        </tr>
    {/foreach}
</table>
<div class="cb"></div>
{/if}
