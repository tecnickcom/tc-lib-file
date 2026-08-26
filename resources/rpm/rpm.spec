# SPEC file

%global c_vendor    %{_vendor}
%global gh_owner    %{_owner}
%global gh_project  %{_project}

Name:      %{_package}
Version:   %{_version}
Release:   %{_release}%{?dist}
Summary:   PHP library for safe low-level file access

License:   LGPLv3+
URL:       https://github.com/%{gh_owner}/%{gh_project}

BuildArch: noarch

Requires:  php(language) >= 8.2.0
Requires:  php-curl
Requires:  php-pcre

Recommends: php-intl

Provides:  php-composer(%{c_vendor}/%{gh_project}) = %{version}
Provides:  php-%{gh_project} = %{version}

%description
PHP library for safe low-level file access: byte-level reads, path
allowlisting, file caching and directory lookup.

%build
#(cd %{_current_directory} && make build)

%install
rm -rf "%{buildroot}"
(cd "%{_current_directory}" && make install DESTDIR="%{buildroot}")

%files
%attr(-,root,root) %{_libpath}
%attr(-,root,root) %{_docpath}
%docdir %{_docpath}
# Optional config files can be listed here when used by a project.

%changelog
* %{_builddate} Nicola Asuni <info@tecnick.com> %{version}-%{release}
- Refer to the project git history for the contents of this release.
* Mon Jul 27 2015 Nicola Asuni <info@tecnick.com> 1.0.0-1
- Initial Commit
