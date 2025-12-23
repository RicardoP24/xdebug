
sap.ui.define(["isr/eve/controller/Base.controller",
    "sap/ui/core/Fragment",
    "sap/ui/model/Filter",
    "sap/ui/model/FilterOperator",
    "isr/eve/control/Input",
    "sap/m/Popover",
    "sap/m/ScrollContainer",
    "sap/m/Toolbar",
    "sap/m/List",
    "sap/m/Text",
    "sap/m/DisplayListItem",
    "sap/m/CustomListItem",
    "sap/m/HBox",
    "sap/m/Label",
    "sap/m/ObjectStatus"],
    function (BaseController, Fragment, Filter, FilterOperator, IsrInput, Popover, ScrollContainer, Toolbar, List, Text, DisplayListItem, CustomListItem, HBox, Label, ObjectStatus) {
        "use strict";
        return BaseController.extend("isr.eve.controller.Debug", {

            atInit: function () {
                if (!this.oUserSessionList) {
                    Fragment.load({ name: "isr.eve.fragment.UserSessionList", controller: this }).then(oPopoverUserSessionList => {
                        this.getView().addDependent(oPopoverUserSessionList);
                        this.oUserSessionList = oPopoverUserSessionList;
                    });
                }
                this.oLocModel.setProperty("/sourceList", []);
                this.callMethod("FINADM03", "sourceList", "").then(oSourceList => {
                    if (oSourceList) this.oLocModel.setProperty("/sourceList", oSourceList);
                });
            },

            onResize: function (oEvent) {
                const oDebugPreferences = {
                    filesWidth: this.oLocModel.getProperty("/debug/filesWidth"),
                    breaksHeight: this.oLocModel.getProperty("/debug/breaksHeight"),
                    consoleHeight: this.oLocModel.getProperty("/debug/consoleHeight"),
                };
                this.oEveModel.update("/Sessions(Session=1)", { DebugPreferences: JSON.stringify(oDebugPreferences) });
            },

            onResizeConsole: function (oEvent) {
                if (oEvent.getParameter("newSizes")[1] == 0) return;
                this.oLocModel.setProperty("/debug/consoleHeight", oEvent.getParameter("newSizes")[1] + "px");
                this.onResize(oEvent);
            },

            onLiveSearchList: function (oEvent) {
                const oSearchButton = oEvent.getSource();
                const oTree = oSearchButton.oParent.getItems()[1].getContent()[0];
                const oBindingInfo = oTree.getBindingInfo("items");
                const sStr = oSearchButton.getValue();
                if (sStr && sStr.length > 2) {
                    const aFilters = [];
                    sStr.split(" ").forEach(sPart => {
                        if (sPart) aFilters.push(new Filter("s", FilterOperator.Contains, sPart));
                    });
                    const oNewFilter = new Filter({ filters: aFilters, and: true });
                    if (oBindingInfo.path) oTree.getBinding("items").filter(oNewFilter, "Application");
                    else {
                        oBindingInfo.path = "/sourceList";
                        oBindingInfo.model = "localModel";
                        oBindingInfo.filters = oNewFilter;
                        oTree.bindAggregation("items", oBindingInfo);
                    }
                    oTree.expandToLevel(99);
                    oTree.setVisible(true);
                }
                else if (oBindingInfo.path) {
                    oBindingInfo.path = "";
                    oTree.bindAggregation("items", oBindingInfo);
                    oTree.setVisible(false);
                }
            },

            onSourceSelect: function (oEvent) {
                const oTreeItem = oEvent.getParameter("listItem");
                if (oTreeItem.isLeaf()) {
                    const oItem = this.oLocModel.getProperty(oTreeItem.getBindingContext("localModel").sPath);
                    this._showSource(oItem.f, 1);
                }
                else {
                    const iIdx = oTreeItem.oParent.indexOfItem(oTreeItem);
                    if (oTreeItem.getExpanded()) oTreeItem.oParent.collapse(iIdx);
                    else oTreeItem.oParent.expand(iIdx);
                }
            },

            _showSource: function (sFilename, iLine) {
                const aSources = this.oLocModel.getProperty("/debug/src");
                const oEditor = this.byId("debugSourceEditor");
                const sFilenameLower = sFilename.toLowerCase();
                this.oLocModel.setProperty("/debug/breakLine", 0);
                if (this.oLocModel.getProperty("/debug/filename") && sFilenameLower == this.oLocModel.getProperty("/debug/filename").toLowerCase()) this._setBreak(oEditor, sFilenameLower, iLine);
                else {
                    this.oLocModel.setProperty("/debug/filename", sFilename);
                    new Promise(resolve => {
                        if (aSources[sFilenameLower]) resolve(aSources[sFilenameLower]);
                        else {
                            oEditor.setBusy(true);
                            this.callMethod("FINADM03", "getSource", sFilename).then(sSource => {
                                aSources[sFilenameLower] = sSource;
                                this.oLocModel.setProperty("/debug/src", aSources);
                                oEditor.setBusy(false);
                                resolve(sSource);
                            });
                        }
                    }).then(sSource => {
                        this.oLocModel.setProperty("/debug/sourceBreakpoints", []);
                        this.oLocModel.setProperty("/debug/source", sSource);
                        const aBreakpoints = [];
                        this.oLocModel.getProperty("/debug/breakpoints").forEach(oBreakpoint => {
                            if (oBreakpoint.f.toLowerCase() == sFilenameLower) aBreakpoints.push(oBreakpoint.l);
                        });
                        this.oLocModel.setProperty("/debug/sourceBreakpoints", aBreakpoints);
                        this._setBreak(oEditor, sFilenameLower, iLine);
                    });
                }
            },

            _setBreak: function (oEditor, sFilenameLower, iLine) {
                const aStack = this.oLocModel.getProperty("/debug/sessionStack");
                if (aStack) {
                    if (iLine) {
                        this.oLocModel.setProperty("/debug/stackSelected", null);
                        for (let s in aStack) if (aStack[s].file.toLowerCase() == sFilenameLower) {
                            this.oLocModel.setProperty("/debug/breakLine", parseInt(aStack[s].line));
                            this.oLocModel.setProperty("/debug/stackSelected", s);
                            break;
                        }
                    }
                    else if (this.oLocModel.getProperty("/debug/stackSelected") != null) {
                        iLine = parseInt(aStack[this.oLocModel.getProperty("/debug/stackSelected")].line);
                        this.oLocModel.setProperty("/debug/breakLine", iLine);
                    }
                }
                if (iLine) oEditor.goToLine(iLine);
            },

            onBreakpointPress: function (oEvent) {
                const oBreakpoint = this.oLocModel.getProperty(oEvent.getParameter("listItem").getBindingContext("localModel").sPath);
                this._showSource(oBreakpoint.f, oBreakpoint.l);
            },

            onRemoveBreakpoint: function (oEvent) {
                const oBreakpoint = this.oLocModel.getProperty(oEvent.oSource.getBindingContext("localModel").sPath);
                const aBreakpoints = this.oLocModel.getProperty("/debug/breakpoints");
                aBreakpoints.splice(aBreakpoints.indexOf(oBreakpoint), 1);
                this.oLocModel.setProperty("/debug/breakpoints", aBreakpoints);
                if (this.oLocModel.getProperty("/debug/filename") && oBreakpoint.f.toLowerCase() == this.oLocModel.getProperty("/debug/filename").toLowerCase()) {
                    const aSourceBreakpoints = JSON.parse(JSON.stringify(this.oLocModel.getProperty("/debug/sourceBreakpoints")));
                    aSourceBreakpoints.splice(aSourceBreakpoints.indexOf(oBreakpoint.l), 1);
                    this.oLocModel.setProperty("/debug/sourceBreakpoints", aSourceBreakpoints);
                }
                this._updateSessionBreakpoint("remove", oBreakpoint.f, oBreakpoint.l);
                this.oEveModel.update("/Sessions(Session=1)", { Breakpoints: JSON.stringify(aBreakpoints) });
            },

            onRemoveAllBreakpoints: function (oEvent) {
                if (this.oLocModel.getProperty("/debug/breakpoints")) {
                    this.oLocModel.setProperty("/debug/breakpoints", []);
                    this.oLocModel.setProperty("/debug/sourceBreakpoints", []);
                    this._updateSessionBreakpoint("remove");
                    this.oEveModel.update("/Sessions(Session=1)", { Breakpoints: "" });
                }
            },

            onBreakpointsChanged: function (oEvent) {
                const aBreakpoints = this.oLocModel.getProperty("/debug/breakpoints");
                const sFilename = this.oLocModel.getProperty("/debug/filename");
                const iLine = oEvent.getParameter("breakpoint");
                if (oEvent.getParameter("reason") == "add") {
                    const oNewBreakpoint = {
                        n: sFilename.substr(sFilename.lastIndexOf("/") + 1),
                        f: sFilename,
                        l: iLine,
                        a: true
                    };
                    aBreakpoints.push(oNewBreakpoint);
                }
                else if (oEvent.getParameter("reason") == "remove") {
                    const sFilenameLower = sFilename.toLowerCase();
                    aBreakpoints.forEach((oBreakpoint, iIdx) => {
                        if (oBreakpoint.f.toLowerCase() == sFilenameLower && oBreakpoint.l == iLine) aBreakpoints.splice(iIdx, 1);
                    });
                }
                this.oLocModel.setProperty("/debug/breakpoints", aBreakpoints);
                this._updateSessionBreakpoint(oEvent.getParameter("reason"), sFilename, iLine);
                this.oEveModel.update("/Sessions(Session=1)", { Breakpoints: JSON.stringify(aBreakpoints) });
            },

            onPopupSessions: function (oEvent) {
                if (!this._sessionsPopover) {
                    this._sessionsPopover = new Popover({
                        showHeader: false,
                        placement: "Bottom",
                        content: [
                            new IsrInput({
                                showValueHelp: true,
                                valueHelpRequest: this.onOpenUserList.bind(this),
                                change: this.onChangeUser.bind(this),
                                value: "{localModel>/debug/userSelected/0/user}",
                                valueState: "{= ${localModel>/debug/userSelected/0/invalid} ? 'Error' : 'None'}",
                                valueStateText: "{localModel>/debug/userSelected/0/invalid}",
                                placeholder: "{i18n>user}"
                            }).addStyleClass("sapUiTinyMarginBottom"),
                            new ScrollContainer({
                                height: "10rem",
                                width: "30rem",
                                vertical: true,
                                content: new List({
                                    mode: "SingleSelectMaster",
                                    itemPress: this.onStartSession.bind(this),
                                    infoToolbar: new Toolbar({
                                        design: "Solid",
                                        content: new Text({ text: "{localModel>/debug/userSelected/0/name}" })
                                    }),
                                    items: {
                                        path: "localModel>/debug/userSessions",
                                        templateShareable: true,
                                        template: new DisplayListItem({
                                            type: "Active",
                                            label: "{localModel>proc} {localModel>title}",
                                            value: {
                                                path: "localModel>time",
                                                formatter: sTime => {
                                                    if (!sTime) return "";
                                                    return sTime.substr(8, 2) + ":" + sTime.substr(10, 2);
                                                }
                                            }
                                        })
                                    }
                                })
                            })
                        ]
                    }).addStyleClass("sapUiContentPadding");
                    this._sessionsPopover.getContent()[1].getContent()[0].setSticky(["InfoToolbar"]);
                    this.getView().addDependent(this._sessionsPopover);
                }
                if (!this.oLocModel.getProperty("/debug/userSelected/0/session")) this.oLocModel.setProperty("/debug/userSelected/", [{
                    session: this.oEveModel.getProperty("/Sessions(Session=1)/SID"),
                    user: this.oEveModel.getProperty("/Sessions(Session=1)/User"),
                    name: this.oEveModel.getProperty("/Sessions(Session=1)/Name"),
                }]);
                this._openSessionList(this.oLocModel.getProperty("/debug/userSelected/0/session"));
                this._sessionsPopover.openBy(oEvent.oSource);
            },

            _openSessionList: function (session) {
                this.callMethod("FINADM03", "getSessionList", {
                    session: session
                }).then(aData => {
                    this.oLocModel.setProperty("/debug/userSessions", aData);
                    oList.setBusy(false);
                });
                this.oLocModel.setProperty("/debug/users", []);
                const oList = this._sessionsPopover.getContent()[1].getContent()[0];
                oList.setBusy(true);
            },

            onOpenUserList: function (oEvent) {
                let sValue = oEvent.getSource().getValue();
                this.oUserSessionList.open();
                this.oUserSessionList._searchField.setValue(sValue);
                if (sValue) this.onSearchUser(oEvent);
            },

            onChangeUser: function (oEvent) {
                let sValue = oEvent.getSource().getProperty("rawValue");
                this.callMethod("FINADM03", "getUsersSession", {
                    user: sValue
                }).then(aData => {
                    if (aData.length == 1) {
                        this.oLocModel.setProperty("/debug/userSelected", aData);
                        this._openSessionList(aData[0].session);
                    }
                    else if (aData.length > 1) {
                        this.oLocModel.setProperty("/debug/userSelected/0/invalid", this.getText("moreThanOne"));
                        this.oLocModel.setProperty("/debug/userSessions", []);
                    }
                    else {
                        this.oLocModel.setProperty("/debug/users", []);
                        this.oLocModel.setProperty("/debug/userSessions", []);
                        this.oLocModel.setProperty("/debug/userSelected", []);
                        this.oLocModel.setProperty("/debug/userSelected", [{ invalid: this.getText("errorInvalidValue") }]);
                    }
                    //Trick to get the button in the shellbar
                    const oButton = this.getView().getContent()[0].getContentAreas()[1].getContentAreas()[0].getItems()[0].getContent()[3];
                    this._sessionsPopover.openBy(oButton);
                });
            },

            onSearchUser: function (oEvent) {
                let sUser = oEvent.getParameter("value");
                if (!sUser) sUser = oEvent.getParameter("_userInputValue");
                this.callMethod("FINADM03", "getUsersSession", {
                    user: sUser
                }).then(aData => {
                    this.oLocModel.setProperty("/debug/users", aData);
                });
            },

            onConfirmUser: function (oEvent) {
                let sPath = oEvent.getParameter("selectedItems")[0].getBindingContext("localModel").sPath;
                const oUser = this.oLocModel.getProperty(sPath);
                this.oLocModel.setProperty("/debug/userSelected/0", oUser);
                this._openSessionList(oUser.session);
                //Trick to get the button in the shellbar
                const oButton = this.getView().getContent()[0].getContentAreas()[1].getContentAreas()[0].getItems()[0].getContent()[3];
                this._sessionsPopover.openBy(oButton);
            },

            _getSessionKey: function (sessionId, sessionTab) {
                return sessionId + "_" + sessionTab;
            },

            /**
            * Obtém dados da sessão ativa de debug
            */
            _getActiveSession: function () {
                const sActiveKey = this.oLocModel.getProperty("/debug/activeSessionKey");
                if (!sActiveKey) return null;
                return this.oLocModel.getProperty("/debug/sessions/" + sActiveKey);
            },

            /**
            * Define qual a sessão ativa
            */
            _setActiveSession: function (sessionId, sessionTab) {
                const sKey = this._getSessionKey(sessionId, sessionTab);
                this.oLocModel.setProperty("/debug/activeSessionKey", sKey);
            },

            onStartSession: function (oEvent) {
                console.log("🔵 onStartSession: INÍCIO");
                const oSession = this.oLocModel.getProperty(oEvent.getParameter("listItem").getBindingContext("localModel").sPath);
                console.log("🔵 oSession:", oSession);

                const sBreakpoints = this.oLocModel.getProperty("/debug/breakpoints").reduce((sBreaks, oBreakpoint) => {
                    if (sBreaks) sBreaks += ";";
                    sBreaks += oBreakpoint.f + "," + oBreakpoint.l;
                    return sBreaks;
                }, "");

                console.log("🔵 Chamando debugSession.php...");
                fetch("debugSession.php", {
                    method: "POST",
                    body: new URLSearchParams("sessionId=" + oSession.id + "&sessionTab=" + oSession.num + "&breaks=" + sBreakpoints)
                }).then(oResult => {
                    console.log("🔵 Fetch concluído, parseando JSON...");
                    return oResult.json();
                }).then(oResponse => {
                    console.log("🔵 JSON recebido:", oResponse);

                    if (oResponse && !oResponse.err) {
                        let sName = oSession.user;
                        if (oSession.proc) sName += ": " + oSession.proc;
                        if (oSession.title) oSession.proc ? sName += " " + oSession.title : sName += ": " + oSession.title;

                        // Criar estrutura de sessões se não existe
                        if (!this.oLocModel.getProperty("/debug/sessions")) {
                            console.log("🔵 Criando estrutura /debug/sessions");
                            this.oLocModel.setProperty("/debug/sessions", {});
                        }

                        // Guardar dados em /debug/sessions/[sessionId_sessionTab]/
                        const sSessionKey = this._getSessionKey(oSession.id, oSession.num);
                        console.log("🔵 SessionKey gerado:", sSessionKey);

                        const oSessionData = {
                            host: oResponse.debugHost,
                            portX: oResponse.debugPortX,
                            portG: oResponse.debugPortG,
                            key: oResponse.sessionKey,
                            name: sName,
                            sessionId: oSession.id,
                            sessionTab: oSession.num,
                            status: "wait",
                            stack: null,
                            stackSelected: null
                        };
                        console.log("🔵 Guardando sessão em /debug/sessions/" + sSessionKey, oSessionData);
                        this.oLocModel.setProperty("/debug/sessions/" + sSessionKey, oSessionData);

                        // Verificar se foi guardado
                        const oVerify = this.oLocModel.getProperty("/debug/sessions/" + sSessionKey);
                        console.log("🔵 Verificação - sessão guardada:", oVerify);

                        // Definir como sessão ativa
                        console.log("🔵 Definindo como sessão ativa:", sSessionKey);
                        this._setActiveSession(oSession.id, oSession.num);

                        // Verificar activeSessionKey
                        const sVerifyKey = this.oLocModel.getProperty("/debug/activeSessionKey");
                        console.log("🔵 activeSessionKey definido:", sVerifyKey);

                        // Compatibilidade com UI
                        this.oLocModel.setProperty("/debug/sessionStatus", "wait");
                        this.oLocModel.setProperty("/debug/sessionName", sName);

                        this._sessionsPopover.close();

                        console.log("🔵 Chamando _sendCommandAndWait('START')...");
                        this._sendCommandAndWait("START");
                    }
                    else {
                        console.log("❌ Erro na resposta:", oResponse);
                        this.msgError("debugNotStarted", [oResponse && oResponse.err ? this.getText(oResponse.err, oResponse.errVar) : ""]);
                    }
                }).catch(error => {
                    console.log("❌ Erro no fetch:", error);
                });
            },


            // onStartSession: function(oEvent) {
            // const oSession = this.oLocModel.getProperty(oEvent.getParameter("listItem").getBindingContext("localModel").sPath);
            // const sBreakpoints = this.oLocModel.getProperty("/debug/breakpoints").reduce((sBreaks, oBreakpoint) => {
            // if (sBreaks) sBreaks += ";";
            // sBreaks += oBreakpoint.f + "," + oBreakpoint.l;
            // return sBreaks;
            // }, "");
            // fetch("debugSession.php", {
            // method: "POST",
            // body: new URLSearchParams("sessionId=" + oSession.id + "&sessionTab=" + oSession.num + "&breaks=" + sBreakpoints)
            // }).then(oResult => oResult.json()).then(oResponse => {
            // if (oResponse && !oResponse.err) {
            // let sName = oSession.user;
            // if (oSession.proc) sName += ": " + oSession.proc;
            // if (oSession.title) oSession.proc ? sName += " " + oSession.title : sName += ": " + oSession.title;
            // this._sessionsPopover.close();
            // this.oLocModel.setProperty("/debug/sessionStatus", "wait");
            // this.oLocModel.setProperty("/debug/sessionHost", oResponse.debugHost);
            // this.oLocModel.setProperty("/debug/sessionPortX", oResponse.debugPortX);
            // this.oLocModel.setProperty("/debug/sessionPortG", oResponse.debugPortG);
            // this.oLocModel.setProperty("/debug/sessionKey", oResponse.sessionKey);
            // this.oLocModel.setProperty("/debug/sessionName", sName);
            // this.oLocModel.setProperty("/debug/sessionId", oSession.id);
            // this.oLocModel.setProperty("/debug/sessionTab", oSession.num);
            // this._sendCommandAndWait("START");
            // }
            // else this.msgError("debugNotStarted", [oResponse && oResponse.err ? this.getText(oResponse.err, oResponse.errVar) : ""]);
            // });

            // },

            onContinueSession: function (oEvent) {
                this._sendCommandAndWait("CONTINUE", "run");
            },

            onStepOverSession: function (oEvent) {
                this._sendCommandAndWait("CONTINUE", "step_over");
            },

            onStepIntoSession: function (oEvent) {
                this._sendCommandAndWait("CONTINUE", "step_into");
            },

            onStepOutSession: function (oEvent) {
                this._sendCommandAndWait("CONTINUE", "step_out");
            },


            onStopSession: function (oEvent) {
                const oSession = this._getActiveSession();
                if (!oSession) return;

                const sStatus = oSession.status;
                if (sStatus == "break") this._sendCommandAndWait("CONTINUE", "stop");
                else if (sStatus == "wait") {
                    if (this._abortController) {
                        this._abortController.abort();
                        this._abortController = null;
                    }
                    this.oLocModel.setProperty("/debug/sessionStatus", "");
                    fetch("debug.php", {
                        method: "POST",
                        body: new URLSearchParams("cmd=STOP&debugHost=" + oSession.host + "&debugPort=" + oSession.portX + "&sessionKey=" + oSession.key)
                    });
                    this._cleanSession();
                }
            },

            // onStopSession: function(oEvent) {
            // const sStatus = this.oLocModel.getProperty("/debug/sessionStatus");
            // if (sStatus == "break") this._sendCommandAndWait("CONTINUE", "stop");
            // else if (sStatus == "wait") {
            // if (this._abortController) {
            // this._abortController.abort();
            // this._abortController = null;
            // }
            // this.oLocModel.setProperty("/debug/sessionStatus", "");
            // fetch("debug.php", {
            // method: "POST",
            // body: new URLSearchParams("cmd=STOP&debugHost=" + this.oLocModel.getProperty("/debug/sessionHost") + "&debugPort=" + this.oLocModel.getProperty("/debug/sessionPortX") + "&sessionKey=" + this.oLocModel.getProperty("/debug/sessionKey"))
            // });
            // this._cleanSession();
            // }
            // },

            onToggleEval: function (oEvent) {
                this.oLocModel.setProperty("/debug/evalOn", !this.oLocModel.getProperty("/debug/evalOn"));
                this.oLocModel.setProperty("/debug/command", "");
                this.byId("debugCommand").focus();
            },

            onCommandEnter: function (oEvent) {
                const sValue = oEvent.getParameter("value");
                if (!sValue) return;

                const oSession = this._getActiveSession();
                if (!oSession) return;

                const bEval = this.oLocModel.getProperty("/debug/evalOn");
                const sXCmd = (bEval ? "EVAL " : "GETVAR ") + sValue;

                fetch("debug.php", {
                    method: "POST",
                    body: new URLSearchParams("cmd=COMMAND&xCmd=" + sXCmd + "&debugHost=" + oSession.host + "&debugPort=" + oSession.portG + "&sessionKey=" + oSession.key)
                }).then(oResult => oResult.json()).then(oResponse => {
                    if (oResponse.data.substr(0, 3) == "ERR") this.msgError("debugError", [oResponse.data.substr(3, 3) == "I18" ? this.getText(oResponse.data.substr(6)) : oResponse.data.substr(6)]);
                    else {
                        const sNewOutput = "<strong" + (bEval ? " class=\"sapThemeBrand-asColor\"" : "") + ">" + sValue + "</strong><pre style=white-space:pre-wrap>" + atob(oResponse.data) + "</pre>";
                        this.oLocModel.setProperty("/debug/console", sNewOutput + this.oLocModel.getProperty("/debug/console"));
                        this.byId("debugConsole").scrollTo(0, 0);
                    }
                });
            },

            // onCommandEnter: function(oEvent) {
            // const sValue = oEvent.getParameter("value");
            // if (!sValue) return;
            // const bEval = this.oLocModel.getProperty("/debug/evalOn");
            // const sXCmd = (bEval ? "EVAL " : "GETVAR ") + sValue;
            // fetch("debug.php", {
            // method: "POST",
            // body: new URLSearchParams("cmd=COMMAND&xCmd=" + sXCmd + "&debugHost=" + this.oLocModel.getProperty("/debug/sessionHost") + "&debugPort=" + this.oLocModel.getProperty("/debug/sessionPortG") + "&sessionKey=" + this.oLocModel.getProperty("/debug/sessionKey"))
            // }).then(oResult => oResult.json()).then(oResponse => {
            // if (oResponse.data.substr(0,3) == "ERR") this.msgError("debugError", [oResponse.data.substr(3,3) == "I18" ? this.getText(oResponse.data.substr(6)) : oResponse.data.substr(6)]);
            // else {
            // const sNewOutput = "<strong" + (bEval ? " class=\"sapThemeBrand-asColor\"" : "") + ">" + sValue + "</strong><pre style=white-space:pre-wrap>" + atob(oResponse.data) + "</pre>";
            // this.oLocModel.setProperty("/debug/console", sNewOutput + this.oLocModel.getProperty("/debug/console"));
            // this.byId("debugConsole").scrollTo(0, 0);
            // }
            // });
            // },

            onClearConsole: function (oEvent) {
                this.oLocModel.setProperty("/debug/console", "");
            },

            onPopupCallStack: function (oEvent) {
                if (!this._stackPopover) {
                    this._stackPopover = new Popover({
                        showHeader: false,
                        placement: "Bottom",
                        content: [
                            new ScrollContainer({
                                height: "20rem",
                                width: "50rem",
                                vertical: true,
                                content: new List({
                                    id: "debugStackList",
                                    mode: "SingleSelectMaster",
                                    itemPress: oEvent => {
                                        const iIdxSelected = oEvent.oSource.indexOfItem(oEvent.getParameter("listItem"));
                                        this.oLocModel.setProperty("/debug/stackSelected", iIdxSelected);
                                        this._showSource(this.oLocModel.getProperty("/debug/sessionStack/" + iIdxSelected + "/file"));
                                        this._stackPopover.close();
                                    },
                                    items: {
                                        path: "localModel>/debug/sessionStack",
                                        templateShareable: true,
                                        template: new CustomListItem({
                                            type: "Active",
                                            content: [
                                                new HBox({
                                                    renderType: "Bare",
                                                    alignItems: "Center",
                                                    //height: "1.6rem",
                                                    items: [
                                                        new HBox({
                                                            width: "100%",
                                                            renderType: "Bare",
                                                            alignItems: "Center",
                                                            justifyContent: "SpaceBetween",
                                                            items: [
                                                                new Label({
                                                                    text: "{localModel>where}",
                                                                    wrapping: true
                                                                }).addStyleClass("sapUiTinyMarginBegin"),
                                                                new HBox({
                                                                    renderType: "Bare",
                                                                    justifyContent: "End",
                                                                    width: "40%",
                                                                    items: [
                                                                        new Label({
                                                                            text: {
                                                                                path: "localModel>file",
                                                                                formatter: sFilename => sFilename.replace(/^.*[\\\/]/, '')
                                                                            }
                                                                        }).addStyleClass("sapUiTinyMarginEnd"),
                                                                        new ObjectStatus({
                                                                            text: "{localModel>line}",
                                                                            inverted: true,
                                                                            state: "None"
                                                                        }).addStyleClass("sapUiTinyMarginEnd")
                                                                    ]
                                                                })
                                                            ]
                                                        }).addStyleClass("sapUiTinyMarginEnd")
                                                    ]
                                                }).addStyleClass("sapUiTinyMarginTop sapUiTinyMarginBottom")
                                            ]
                                        })
                                    }
                                })
                            })
                        ]
                    }).addStyleClass("sapUiContentPadding");
                    this.getView().addDependent(this._stackPopover);
                }
                const oList = sap.ui.getCore().byId("debugStackList");
                const iIdxSelected = this.oLocModel.getProperty("/debug/stackSelected");
                if (iIdxSelected === null) oList.removeSelections();
                else oList.setSelectedItem(oList.getItems()[iIdxSelected]);
                this._stackPopover.openBy(oEvent.oSource);
            },

            _sendCommandAndWait: function (sCmd, sXCmd) {
                console.log("🟢 _sendCommandAndWait: INÍCIO", "cmd=" + sCmd, "xCmd=" + sXCmd);

                const sActiveKey = this.oLocModel.getProperty("/debug/activeSessionKey");
                console.log("🟢 activeSessionKey:", sActiveKey);

                const oSession = this._getActiveSession();
                console.log("🟢 _getActiveSession() retornou:", oSession);

                if (!oSession) {
                    console.error("❌ _sendCommandAndWait: Nenhuma sessão ativa! ABORTANDO!");
                    return;
                }

                console.log("🟢 Sessão encontrada OK. Prosseguindo...");

                if (this._abortController) {
                    console.log("🟢 Abortando controller anterior");
                    this._abortController.abort();
                }
                this._abortController = new AbortController();

                let sStatus = "run";
                if (sCmd == "START") {
                    sXCmd = "START";
                    sStatus = "wait";
                    console.log("🟢 É START, iniciando _checkKey timer");
                    this._timCheckKey = setTimeout(this._checkKey.bind(this), 10000);
                }

                // Atualizar status
                const sSessionKey = this.oLocModel.getProperty("/debug/activeSessionKey");
                console.log("🟢 Atualizando status para:", sStatus);
                this.oLocModel.setProperty("/debug/sessions/" + sSessionKey + "/status", sStatus);
                this.oLocModel.setProperty("/debug/sessionStatus", sStatus);

                const sUrl = "cmd=" + sCmd + "&xCmd=" + sXCmd + "&debugHost=" + oSession.host + "&debugPort=" + oSession.portG + "&sessionKey=" + oSession.key;
                console.log("🟢 Fazendo fetch para debug.php:", sUrl);

                fetch("debug.php", {
                    signal: this._abortController.signal,
                    method: "POST",
                    body: new URLSearchParams(sUrl)
                }).then(oResult => {
                    console.log("🟢 Fetch concluído, parseando JSON...");
                    return oResult.json();
                }).then(oResponse => {
                    console.log("🟢 Resposta recebida:", oResponse);
                    this._abortController = null;

                    // Atualizar status
                    this.oLocModel.setProperty("/debug/sessions/" + sSessionKey + "/status", oResponse.status);
                    this.oLocModel.setProperty("/debug/sessionStatus", oResponse.status);

                    if (oResponse.err) {
                        console.log("❌ Erro na resposta:", oResponse.err);
                        this._cleanSession();
                        this.msgError("debugError", [this.getText(oResponse.err)]);
                    }
                    else if (oResponse.status == "break") {
                        console.log("🟢 STATUS = break");
                        if (sCmd == "START") {
                            console.log("🟢 Era START, chamando run automaticamente");
                            this._sendCommandAndWait("CONTINUE", "run");
                        }
                        else {
                            console.log("🟢 Processando break, obtendo stack...");
                            setTimeout(() => {
                                if (!this.oLocModel.getProperty("/debug/sessionStack")) {
                                    this.oLocModel.setProperty("/debug/filename", "");
                                    this.oLocModel.setProperty("/debug/src", []);
                                }

                                const aStack = JSON.parse(oResponse.stack);
                                this.oLocModel.setProperty("/debug/sessions/" + sSessionKey + "/stack", aStack);
                                this.oLocModel.setProperty("/debug/sessions/" + sSessionKey + "/stackSelected", 0);
                                this.oLocModel.setProperty("/debug/sessionStack", aStack);
                                this.oLocModel.setProperty("/debug/stackSelected", 0);

                                this._showSource(aStack[0].file);
                                this.byId("debugCommand").focus();
                            }, 100);
                        }
                    }
                    else if (oResponse.status == "wait") {
                        console.log("🟢 STATUS = wait, reiniciando...");
                        this._sendCommandAndWait("START");
                        this.oLocModel.setProperty("/debug/sessions/" + sSessionKey + "/stack", null);
                        this.oLocModel.setProperty("/debug/sessions/" + sSessionKey + "/stackSelected", null);
                        this.oLocModel.setProperty("/debug/sessionStack", null);
                        this.oLocModel.setProperty("/debug/stackSelected", null);
                        this.oLocModel.setProperty("/debug/breakLine", 0);
                        this.oLocModel.setProperty("/debug/evalOn", false);
                        this.oLocModel.setProperty("/debug/command", "");
                    }
                    else if (!oResponse.status) {
                        console.log("❌ STATUS vazio, limpando sessão");
                        this._cleanSession();
                    }
                }).catch((error) => {
                    console.log("❌ Erro no fetch:", error);
                });
            },

            // _sendCommandAndWait: function(sCmd, sXCmd) {
            // if (this._abortController) this._abortController.abort();
            // this._abortController = new AbortController();
            // let sStatus = "run";
            // if (sCmd == "START") {
            // sXCmd = "START";
            // sStatus = "wait";
            // this._timCheckKey = setTimeout(this._checkKey.bind(this), 10000);
            // }
            // this.oLocModel.setProperty("/debug/sessionStatus", sStatus);
            // fetch("debug.php", {
            // signal: this._abortController.signal,
            // method: "POST",
            // body: new URLSearchParams("cmd=" + sCmd + "&xCmd=" + sXCmd + "&debugHost=" + this.oLocModel.getProperty("/debug/sessionHost") + "&debugPort=" + this.oLocModel.getProperty("/debug/sessionPortG") + "&sessionKey=" + this.oLocModel.getProperty("/debug/sessionKey"))
            // }).then(oResult => oResult.json()).then(oResponse => {
            // this._abortController = null;
            // this.oLocModel.setProperty("/debug/sessionStatus", oResponse.status);
            // if (oResponse.err) {
            // this._cleanSession();
            // this.msgError("debugError", [this.getText(oResponse.err)]);
            // }
            // else if (oResponse.status == "break") {
            // if (sCmd == "START") this._sendCommandAndWait("CONTINUE", "run");
            // else {
            // setTimeout(() => {
            // if (!this.oLocModel.getProperty("/debug/sessionStack")) {
            // this.oLocModel.setProperty("/debug/filename", "");
            // this.oLocModel.setProperty("/debug/src", []);
            // }
            // this.oLocModel.setProperty("/debug/sessionStack", JSON.parse(oResponse.stack));
            // this.oLocModel.setProperty("/debug/stackSelected", 0);
            // this._showSource(this.oLocModel.getProperty("/debug/sessionStack/0/file"));
            // this.byId("debugCommand").focus();
            // }, 100);
            // }
            // }
            // else if (oResponse.status == "wait") {
            // this._sendCommandAndWait("START");
            // this.oLocModel.setProperty("/debug/sessionStack", null);
            // this.oLocModel.setProperty("/debug/stackSelected", null);
            // this.oLocModel.setProperty("/debug/breakLine", 0);
            // this.oLocModel.setProperty("/debug/evalOn", false);
            // this.oLocModel.setProperty("/debug/command", "");
            // }
            // else if (!oResponse.status) this._cleanSession();
            // }).catch(() => {});
            // },

            _cleanSession: function () {
                if (this._timCheckKey) clearTimeout(this._timCheckKey);

                const sSessionKey = this.oLocModel.getProperty("/debug/activeSessionKey");
                if (sSessionKey) {
                    // ✅ REMOVER sessão do objeto
                    const oSessions = this.oLocModel.getProperty("/debug/sessions");
                    delete oSessions[sSessionKey];
                    this.oLocModel.setProperty("/debug/sessions", oSessions);
                }

                // ✅ LIMPAR sessão ativa
                this.oLocModel.setProperty("/debug/activeSessionKey", "");

                // ✅ MANTIDO: Limpar UI
                this.oLocModel.setProperty("/debug/sessionStatus", "");
                this.oLocModel.setProperty("/debug/sessionName", "");
                this.oLocModel.setProperty("/debug/sessionStack", null);
                this.oLocModel.setProperty("/debug/stackSelected", null);
                this.oLocModel.setProperty("/debug/breakLine", 0);
            },

            // _cleanSession: function() {
            // if (this._timCheckKey) clearTimeout(this._timCheckKey);
            // this.oLocModel.setProperty("/debug/sessionHost", "");
            // this.oLocModel.setProperty("/debug/sessionPortX", "");
            // this.oLocModel.setProperty("/debug/sessionPortG", "");
            // this.oLocModel.setProperty("/debug/sessionKey", "");
            // this.oLocModel.setProperty("/debug/sessionName", "");
            // this.oLocModel.setProperty("/debug/sessionId", "");
            // this.oLocModel.setProperty("/debug/sessionTab", "");
            // this.oLocModel.setProperty("/debug/sessionStack", null);
            // this.oLocModel.setProperty("/debug/stackSelected", null);
            // this.oLocModel.setProperty("/debug/breakLine", 0);
            // },

            // _updateSessionBreakpoint: function(sAction, sFilename, iLine) {
            // const sStatus = this.oLocModel.getProperty("/debug/sessionStatus");
            // if (!sStatus) return;
            // fetch("debug.php", {
            // method: "POST",
            // body: new URLSearchParams("cmd=BREAK&debugHost=" + this.oLocModel.getProperty("/debug/sessionHost") + "&debugPort=" + this.oLocModel.getProperty("/debug/sessionPort" + (sStatus == "wait" ? "X" : "G")) + "&sessionKey=" + this.oLocModel.getProperty("/debug/sessionKey") + "&action=" + sAction + (sFilename ? "&filename=" + sFilename + "&line=" + iLine : ""))
            // });
            // },

            _updateSessionBreakpoint: function (sAction, sFilename, iLine) {
                const oSession = this._getActiveSession();
                if (!oSession) return;

                const sStatus = oSession.status;

                fetch("debug.php", {
                    method: "POST",
                    body: new URLSearchParams("cmd=BREAK&debugHost=" + oSession.host + "&debugPort=" + oSession[sStatus == "wait" ? "portX" : "portG"] + "&sessionKey=" + oSession.key + "&action=" + sAction + (sFilename ? "&filename=" + sFilename + "&line=" + iLine : ""))
                });
            },


            _checkKey: function () {
                const oSession = this._getActiveSession();
                if (!oSession) return;

                const sStatus = oSession.status;

                fetch("debug.php", {
                    method: "POST",
                    body: new URLSearchParams("cmd=KEY&debugHost=" + oSession.host + "&debugPort=" + oSession[sStatus == "break" ? "portG" : "portX"] + "&sessionKey=" + oSession.key)
                }).then(oResult => oResult.json()).then(oResponse => {
                    if (oResponse.ok) this._timCheckKey = setTimeout(this._checkKey.bind(this), 10000);
                    else {
                        this.oLocModel.setProperty("/debug/sessionStatus", "");
                        this._cleanSession();
                    }
                });
            }

            // _checkKey: function() {
            // const sStatus = this.oLocModel.getProperty("/debug/sessionStatus");
            // if (!sStatus) return;
            // fetch("debug.php", {
            // method: "POST",
            // body: new URLSearchParams("cmd=KEY&debugHost=" + this.oLocModel.getProperty("/debug/sessionHost") + "&debugPort=" + this.oLocModel.getProperty("/debug/sessionPort" + (sStatus == "break" ? "G" : "X")) + "&sessionKey=" + this.oLocModel.getProperty("/debug/sessionKey"))
            // }).then(oResult => oResult.json()).then(oResponse => {
            // if (oResponse.ok) this._timCheckKey = setTimeout(this._checkKey.bind(this), 10000);
            // else {
            // this.oLocModel.setProperty("/debug/sessionStatus", "");
            // this._cleanSession();
            // }
            // });
            // }

        });
    });
