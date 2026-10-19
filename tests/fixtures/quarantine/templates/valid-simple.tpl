<html>
<body>
<p>Hello {{ username }}, {{ counter }} new item{% if counter != 1 %}s{% endif %} on {{ hostname }}:</p>
<ul>
{% for line in meta %}
  <li>{{ line.id }}: {{ line.subject|e }} from {{ line.sender }} ({{ line.score }}, {{ line.action }}, {{ line.created }}){% if quarantine_acl == 1 %} <a href="https://{{ hostname }}/qhandler/release/{{ line.qhash }}">release</a>{% endif %}</li>
{% endfor %}
</ul>
</body>
</html>
